<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


namespace Widgets\PieChart\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CRangeTimeParser,
	CHousekeepingHelper,
	CMacrosResolverHelper,
	CParser,
	CSimpleIntervalParser,
	Manager;

use Widgets\PieChart\Includes\{
	CWidgetFieldDataSet,
	WidgetForm
};


class WidgetView extends CControllerDashboardWidgetView {

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			//'from' => 'string',
			//'to' => 'string'
		]);
	}

	protected function doAction(): void {

		$dashboard_time = !WidgetForm::hasOverrideTime($this->fields_values);
//		if ($dashboard_time) {
//			$from = $this->getInput('from');
//			$to = $this->getInput('to');
//		}
//		else {
			$from = $this->fields_values['time_from'];
			$to = $this->fields_values['time_to'];
		//}

		$range_time_parser = new CRangeTimeParser();

		$range_time_parser->parse($from);
		$time_from = $range_time_parser->getDateTime(true)->getTimestamp();

		$range_time_parser->parse($to);
		$time_to = $range_time_parser->getDateTime(false)->getTimestamp();

		$pie_chart_options = [
			'data_sets' => array_values($this->fields_values['ds']),
			'data_source' => $this->fields_values['source'],
			'dashboard_time' => $dashboard_time,
			'time_period' => [
				'time_from' => $time_from,
				'time_to' => $time_to
			],
			'templateid' => $this->getInput('templateid', ''),
			'merge_sectors' => [
				'merge' => $this->fields_values['merge'],
				'percent' => $this->fields_values['merge'] == PIE_CHART_MERGE_ON
					? $this->fields_values['merge_percent']
					: null,
				'color' => $this->fields_values['merge'] == PIE_CHART_MERGE_ON
					? '#'.$this->fields_values['merge_color']
					: null
			],
			'total_value' => [
				'total_show' => $this->fields_values['total_show'],
				'decimal_places' => $this->fields_values['total_show'] == PIE_CHART_SHOW_TOTAL_ON
					? $this->fields_values['decimal_places']
					: null
			],
			'units' => [
				'units_show' => $this->fields_values['units_show'],
				'units_value' => $this->fields_values['units_show'] == PIE_CHART_SHOW_UNITS_ON
					? $this->fields_values['units']
					: null
			]
		];

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'info' => $this->makeWidgetInfo(),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			],
			'vars' => []
		];

		$data['vars']['config'] = $this->getConfig();

		$metrics = $this->getData($pie_chart_options);

		if ($metrics['errors']) {
			error($metrics['errors']);
		}

		$data['vars']['sectors'] = $metrics['sectors'];
		$data['vars']['legend'] = $this->getLegend($metrics['sectors']);
		$data['vars']['total_value'] = $metrics['total_value'];

		$this->setResponse(new CControllerResponseData($data));
	}

	private function getData($options): array {
		$metrics = [];
		$errors = [];
		$total_value = 0;

		self::getItems($metrics, $options['data_sets'], $options['templateid']);
		self::sortByDataset($metrics);
		self::getTimePeriod($metrics, $options['time_period']);
		self::getChartDataSource($metrics, $errors, $options['data_source']);
		self::getMetricsData($metrics, $options['data_sets']);
		self::getSectorsData($metrics, $total_value, $options['merge_sectors'], $options['total_value'], $options['units']);

		return [
			'sectors' => $metrics,
			'total_value' => $total_value,
			'errors' => $errors
		];
	}

	private function makeWidgetInfo(): array {
		$info = [];

		if (WidgetForm::hasOverrideTime($this->fields_values)) {
			$info[] = [
				'icon' => ZBX_ICON_TIME_PERIOD,
				'hint' => relativeDateToText($this->fields_values['time_from'], $this->fields_values['time_to'])
			];
		}

		return $info;
	}

	private static function getItems(array &$metrics, array $data_sets, string $templateid): void {
		$metrics = [];
		$max_metrics = 50;

		foreach ($data_sets as $index => $data_set) {
			if ($data_set['dataset_type'] == CWidgetFieldDataSet::DATASET_TYPE_SINGLE_ITEM) {
				if (!$data_set['itemids']) {
					continue;
				}

				if ($max_metrics == 0) {
					break;
				}

				if ($templateid !== '') {
					$tmp_items = API::Item()->get([
						'output' => ['key_'],
						'itemids' => $data_set['itemids'],
						'webitems' => true
					]);

					if ($tmp_items) {
						$items = API::Item()->get([
							'output' => ['itemid'],
							'hostids' => [$templateid],
							'webitems' => true,
							'filter' => [
								'key_' => array_column($tmp_items, 'key_')
							]
						]);
						$data_set['itemids'] = $items ? array_column($items, 'itemid') : null;
					}
				}

				$items_db = API::Item()->get([
					'output' => ['itemid', 'hostid', 'name', 'history', 'trends', 'units', 'value_type'],
					'selectHosts' => ['name'],
					'webitems' => true,
					'filter' => [
						'value_type' => [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT]
					],
					'itemids' => $data_set['itemids'],
					'preservekeys' => true,
					'limit' => $max_metrics
				]);

				$items = [];

				foreach ($data_set['itemids'] as $itemid) {
					if (array_key_exists($itemid, $items_db)) {
						$items[] = $items_db[$itemid];
					}
				}

				if (!$items) {
					continue;
				}

				unset($data_set['itemids']);

				$colors = $data_set['color'];
				$types = $data_set['type'];

				foreach ($items as $item) {
					$data_set['color'] = '#'.array_shift($colors);
					$data_set['type'] = array_shift($types);
					$metrics[] = $item + ['data_set' => $index, 'options' => $data_set];
					$max_metrics--;
				}
			}

			if ($templateid === '') {
				if (!$data_set['hosts'] || !$data_set['items']) {
					continue;
				}
			}
			else {
				if (!$data_set['items']) {
					continue;
				}
			}

			if ($max_metrics == 0) {
				break;
			}

			$options = [
				'output' => ['itemid', 'hostid', 'name', 'history', 'trends', 'units', 'value_type'],
				'selectHosts' => ['name'],
				'webitems' => true,
				'filter' => [
					'value_type' => [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT]
				],
				'search' => [
					'name' => self::processPattern($data_set['items'])
				],
				'searchWildcardsEnabled' => true,
				'searchByAny' => true,
				'sortfield' => 'name',
				'sortorder' => ZBX_SORT_UP,
				'limit' => $max_metrics
			];

			if ($templateid === '') {
				$hosts = API::Host()->get([
					'output' => [],
					'search' => [
						'name' => self::processPattern($data_set['hosts'])
					],
					'searchWildcardsEnabled' => true,
					'searchByAny' => true,
					'preservekeys' => true
				]);

				if ($hosts) {
					$options['hostids'] = array_keys($hosts);
				}
			}
			else {
				$options['hostids'] = $templateid;
			}

			$items = null;

			if (array_key_exists('hostids', $options) && $options['hostids']) {
				$items = API::Item()->get($options);
			}

			if (!$items) {
				continue;
			}

			unset($data_set['itemids'], $data_set['items']);

			$colors = getColorVariations('#' . $data_set['color'], count($items));

			foreach ($items as $item) {
				$data_set['color'] = array_shift($colors);
				$metrics[] = $item + ['data_set' => $index, 'options' => $data_set];
				$max_metrics--;
			}
		}
	}

	private static function sortByDataset(array &$metrics): void {
		usort($metrics, static function(array $a, array $b): int {
			return $a['data_set'] <=> $b['data_set'];
		});
	}

	private static function getTimePeriod(array &$metrics, array $time_period): void {
		foreach ($metrics as &$metric) {
			$metric['time_period'] = $time_period;
		}
		unset($metric);
	}

	private static function getChartDataSource(array &$metrics, array &$errors, int $data_source): void {
		/**
		 * If data source is not specified, calculate it automatically. Otherwise, set given $data_source to each
		 * $metric.
		 */
		if ($data_source == PIE_CHART_DATA_SOURCE_AUTO) {
			/**
			 * First, if global configuration setting "Override item history period" is enabled, override globally
			 * specified "Data storage period" value to each metric custom history storage duration, converting it
			 * to seconds. If "Override item history period" is disabled, item level field 'history' will be used
			 * later, but now we are just storing the field name 'history' in array $to_resolve.
			 *
			 * Do the same with trends.
			 */
			$to_resolve = [];

			if (CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL)) {
				foreach ($metrics as &$metric) {
					$metric['history'] = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY));
				}
				unset($metric);
			}
			else {
				$to_resolve[] = 'history';
			}

			if (CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_GLOBAL)) {
				foreach ($metrics as &$metric) {
					$metric['trends'] = timeUnitToSeconds(CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS));
				}
				unset($metric);
			}
			else {
				$to_resolve[] = 'trends';
			}

			// If no global history and trend override enabled, resolve 'history' and/or 'trends' values for given $metric.
			if ($to_resolve) {
				$metrics = CMacrosResolverHelper::resolveTimeUnitMacros($metrics, $to_resolve);
				$simple_interval_parser = new CSimpleIntervalParser();

				foreach ($metrics as $num => &$metric) {
					// Convert its values to seconds.
					if (!CHousekeepingHelper::get(CHousekeepingHelper::HK_HISTORY_GLOBAL)) {
						if ($simple_interval_parser->parse($metric['history']) != CParser::PARSE_SUCCESS) {
							$errors[] = _s('Incorrect value for field "%1$s": %2$s.', 'history',
								_('invalid history storage period')
							);
							unset($metrics[$num]);
						}
						else {
							$metric['history'] = timeUnitToSeconds($metric['history']);
						}
					}

					if (!CHousekeepingHelper::get(CHousekeepingHelper::HK_TRENDS_GLOBAL)) {
						if ($simple_interval_parser->parse($metric['trends']) != CParser::PARSE_SUCCESS) {
							$errors[] = _s('Incorrect value for field "%1$s": %2$s.', 'trends',
								_('invalid trend storage period')
							);
							unset($metrics[$num]);
						}
						else {
							$metric['trends'] = timeUnitToSeconds($metric['trends']);
						}
					}
				}
				unset($metric);
			}

			foreach ($metrics as &$metric) {
				/**
				 * History as a data source is used in 2 cases:
				 * 1) if trends are disabled (set to 0) either for particular $metric item or globally;
				 * 2) if period for requested data is newer than the period of keeping history for particular $metric
				 *    item.
				 *
				 * Use trends otherwise.
				 */
				$history = $metric['history'];
				$trends = $metric['trends'];
				$time_from = $metric['time_period']['time_from'];

				$metric['source'] = ($trends == 0 || (time() - $history < $time_from))
					? PIE_CHART_DATA_SOURCE_HISTORY
					: PIE_CHART_DATA_SOURCE_TRENDS;
			}
		}
		else {
			foreach ($metrics as &$metric) {
				$metric['source'] = $data_source;
			}
		}

		unset($metric);
	}

	private static function getMetricsData(array &$metrics, array $data_sets): void {
		$dataset_metrics = [];

		foreach ($metrics as $metric_num => &$metric) {
			$dataset_num = $metric['data_set'];

			if ($metric['options']['dataset_aggregation'] == AGGREGATE_NONE) {
				$name = self::aggr_fnc2str($metric['options']['aggregate_function']).
					'('.$metric['hosts'][0]['name'].NAME_DELIMITER.$metric['name'].')';
			}
			else {
				$name = $data_sets[$dataset_num]['data_set_label'] !== ''
					? $data_sets[$dataset_num]['data_set_label']
					: _('Data set').' #'.($dataset_num + 1);
			}

			$item = [
				'itemid' => $metric['itemid'],
				'value_type' => $metric['value_type'],
				'source' => ($metric['source'] == PIE_CHART_DATA_SOURCE_HISTORY) ? 'history' : 'trends'
			];

			if (!array_key_exists($dataset_num, $dataset_metrics)) {
				$metric = array_merge($metric, [
					'name' => $name,
					'items' => [],
					'value' => null
				]);

				$aggregate_interval = $metric['time_period']['time_to'] - $metric['time_period']['time_from'];

				if ($aggregate_interval === null || $aggregate_interval < 1
					|| $aggregate_interval > ZBX_MAX_TIMESHIFT) {
					continue;
				}

				$metric['options']['aggregate_interval'] = (int) $aggregate_interval;

				if ($metric['options']['dataset_aggregation'] != AGGREGATE_NONE) {
					$dataset_metrics[$dataset_num] = $metric_num;
				}

				$metric['items'][] = $item;
			}
			else {
				$metrics[$dataset_metrics[$dataset_num]]['items'][] = $item;
				unset($metrics[$metric_num]);
			}
		}
		unset($metric);

		foreach ($metrics as &$metric) {
			if (!$metric['items']) {
				continue;
			}

			$results = Manager::History()->getAggregationByInterval(
				$metric['items'], $metric['time_period']['time_from'], $metric['time_period']['time_to'],
				$metric['options']['aggregate_function'], $metric['options']['aggregate_interval']
			);

			if ($results) {
				$values = [];

				foreach ($results as $result) {
					$values[] = $result['data'][0]['value'];
				}

				if ($metric['options']['dataset_aggregation'] != AGGREGATE_NONE) {
					switch($metric['options']['dataset_aggregation']) {
						case AGGREGATE_MAX:
							$metric['value'] = max($values);
							break;
						case AGGREGATE_MIN:
							$metric['value'] = min($values);
							break;
						case AGGREGATE_AVG:
							$metric['value'] = array_sum($values)/count(array_filter($values));
							break;
						case AGGREGATE_COUNT:
							$metric['value'] = count($values);
							break;
						case AGGREGATE_SUM:
							$metric['value'] = array_sum($values);
							break;
					}
				}
				else {
					$metric['value'] = $values[0];
				}
			}
		}
	}

	private static function getSectorsData(array &$metrics, int &$total_value, array $merge_sectors, array $total_config, array $units_config): void {
		$set_default_item = true;
		$raw_total_value = 0;
		$others_value = 0;
		$below_threshold_count = 0;
		$to_remove = [];

		foreach ($metrics as &$metric) {
			$is_total = ($metric['options']['dataset_aggregation'] == AGGREGATE_NONE
					&& $metric['options']['type'] == PIE_CHART_ITEM_TOTAL);

			foreach ($metric['items'] as $item) {
				if ($item['itemid'] === $metric['itemid']) {
					$metric['item'] = $item;
					break;
				}
			}

			unset($metric['items']);

			if ($units_config['units_show'] == PIE_CHART_SHOW_UNITS_ON) {
				if ($units_config['units_value'] !== '' && isset($metric['item'])) {
					$metric['item']['units'] = $units_config['units_value'];
				}
				else {
					$metric['item']['units'] = $metric['units'];
				}
			}
			else {
				$metric['item']['units'] = '';
			}

			if ($set_default_item && isset($metric['item'])) {
				$default_item = $metric['item'];
				$set_default_item = false;
			}

			$formatted_value = convertUnitsRaw([
				'value' => $metric['value'],
				'units' => $metric['item']['units'],
				'small_scientific' => false,
				'zero_as_zero' => false
			]);

			$metric = [
				'name' => $metric['name'],
				'color' => $metric['options']['color'],
				'value' => $metric['value'],
				'formatted_value' => $formatted_value,
				'is_total' => $is_total
			];

			$raw_total_value += $metric['value'];
		}

		unset($metric);

		foreach ($metrics as $metric) {
			if ($metric['is_total']) {
				$raw_total_value = $metric['value'];
				break;
			}
		}

		foreach ($metrics as &$metric) {
			if (!$metric['is_total'] && $raw_total_value > 0) {
				$percentage = ($metric['value'] / $raw_total_value) * 100;
				$metric['percent_of_total'] = $percentage;
			}
			else {
				$metric['percent_of_total'] = 100;
			}

			if ($merge_sectors['merge'] == PIE_CHART_MERGE_ON) {
				if ($metric['percent_of_total'] < $merge_sectors['percent']) {
					$below_threshold_count++;
					$others_value += $metric['value'];
					$to_remove[] = &$metric;
				}
			}
		}

		unset($metric);

		if ($below_threshold_count >= 2) {
			$others_formatted_value = convertUnitsRaw([
				'value' => $others_value,
				'units' => $default_item['units'],
				'small_scientific' => false,
				'zero_as_zero' => false
			]);

			$others_metric = [
				'name' => _('Others'),
				'color' => $merge_sectors['color'],
				'value' => $others_value,
				'formatted_value' => $others_formatted_value,
				'is_total' => false,
				'percent_of_total' => ($others_value / $raw_total_value) * 100
			];

			$metrics[] = &$others_metric;
		}

		foreach ($to_remove as $metric_to_remove) {
			$index = array_search($metric_to_remove, $metrics, true);
			if ($index !== false) {
				unset($metrics[$index]);
			}
		}

		$total_value = convertUnitsRaw([
			'value' => $raw_total_value,
			'units' => $default_item['units'] ?? '',
			'decimals' => $total_config['decimal_places'],
			'decimals_exact' => true,
			'small_scientific' => false,
			'zero_as_zero' => false
		]);
	}

	/**
	 * Prepare an array to be used for hosts/items filtering.
	 *
	 * @param array  $patterns  Array of strings containing hosts/items patterns.
	 *
	 * @return array|mixed  Returns array of patterns.
	 *                      Returns NULL if array contains '*' (so any possible host/item search matches).
	 */
	private static function processPattern(array $patterns): ?array {
		return in_array('*', $patterns, true) ? null : $patterns;
	}

	private static function aggr_fnc2str($calc_fnc) {
		switch ($calc_fnc) {
			case AGGREGATE_NONE:
				return _('none');
			case AGGREGATE_MIN:
				return _('min');
			case AGGREGATE_MAX:
				return _('max');
			case AGGREGATE_AVG:
				return _('avg');
			case AGGREGATE_COUNT:
				return _('count');
			case AGGREGATE_SUM:
				return _('sum');
			case AGGREGATE_FIRST:
				return _('first');
			case AGGREGATE_LAST:
				return _('last');
		}
	}

	private function getConfig(): array {
		$config = [
			'draw_type' => $this->fields_values['draw_type'],
			'stroke' => $this->fields_values['stroke'],
			'space' => $this->fields_values['space'],
		];

		if ($this->fields_values['draw_type'] == PIE_CHART_DRAW_DOUGHNUT) {
			$config['width'] = $this->fields_values['width'];

			if ($this->fields_values['total_show'] == PIE_CHART_SHOW_TOTAL_ON) {
				$config['total_value'] = [
					'show' => true,
					'size' => $this->fields_values['value_size'],
					'is_bold' =>  $this->fields_values['value_bold'] == PIE_CHART_VALUE_BOLD_ON,
					'color' => '#'.$this->fields_values['value_color'],
					'units_show' => $this->fields_values['units_show'] == PIE_CHART_SHOW_UNITS_ON
				];
			}
			else {
				$config['total_value'] = [
					'show' => false
				];
			}
		}

		return $config;
	}

	private function getLegend(array $sectors): array {
		$legend['data'] = [];

		foreach ($sectors as $sector) {
			$legend['data'][] = [
				'name' => $sector['name'],
				'color' => $sector['color']
			];
		}

		if ($this->fields_values['legend'] == PIE_CHART_LEGEND_ON) {
			$legend['show'] = true;
			$legend['lines'] = $this->fields_values['legend_lines'];
			$legend['columns'] = $this->fields_values['legend_columns'];
		}
		else {
			$legend['show'] = false;
		}

		return $legend;
	}
}
