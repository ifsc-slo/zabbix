<?php declare(strict_types = 1);
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
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


class CMacroValue extends CInput {

	/**
	 * Container class.
	 */
	public const ZBX_STYLE_INPUT_GROUP = 'input-group macro-value';

	/**
	 * Button class for undo.
	 */
	public const ZBX_STYLE_BTN_UNDO = 'btn-undo';

	/**
	 * Add element initialization javascript.
	 *
	 * @var bool
	 */
	public $add_post_js = true;

	/**
	 * Class constructor.
	 *
	 * @param int    $type           Macro type one of ZBX_MACRO_TYPE_SECRET or ZBX_MACRO_TYPE_TEXT value.
	 * @param string $name           Macro input name.
	 * @param string $value          Macro value, null when value will not be set.
	 * @param bool   $add_post_js    Add element initialization javascript.
	 */
	public function __construct(string $type, string $name, string $value = null, $add_post_js = true) {
		parent::__construct($type, $name, $value);

		$this->add_post_js = $add_post_js;
		$this->setId(uniqid('macro-value-'));
	}

	/**
	 * Get content of all Javascript code.
	 *
	 * @return string  Javascript code.
	 */
	public function getPostJS(): string {
		return 'jQuery("#'.$this->getId().'").macroValue();';
	}

	/**
	 * Render object.
	 *
	 * @param boolean $destroy
	 *
	 * @return string
	 */
	public function toString($destroy = true) {
		$name = $this->getAttribute('name');
		$value_type = $this->getAttribute('type');
		$value = $this->getAttribute('value');
		$node = (new CDiv())
			->addClass(self::ZBX_STYLE_INPUT_GROUP)
			->setWidth(ZBX_TEXTAREA_MACRO_VALUE_WIDTH);

		if ($value_type == ZBX_MACRO_TYPE_TEXT) {
			$class = ZBX_STYLE_ICON_TEXT;
			$node->addItem((new CTextAreaFlexible($name.'[value]', $value, ['add_post_js' => $this->add_post_js]))
				->setAttribute('placeholder', _('value'))
				->setReadonly($this->getAttribute('readonly'))
			);
		}
		else {
			$class = ZBX_STYLE_ICON_SECRET_TEXT;
			$node->addItem([
				(new CInputSecret($name.'[value]', $value, $this->add_post_js))
					->setAttribute('placeholder', _('value')),
				(new CButton(null))
					->setAttribute('title', _('Revert changes'))
					->addClass(ZBX_STYLE_BTN_ALT)
					->addClass(self::ZBX_STYLE_BTN_UNDO)
			]);
		}

		$node->addItem((new CButtonDropdown($name.'[type]',  $value_type, [
				['label' => _('Text'), 'value' => ZBX_MACRO_TYPE_TEXT, 'class' => ZBX_STYLE_ICON_TEXT],
				['label' => _('Secret text'), 'value' => ZBX_MACRO_TYPE_SECRET, 'class' => ZBX_STYLE_ICON_SECRET_TEXT]
			]))
				->addClass($class)
				->setAttribute('disabled', $this->getAttribute('disabled'))
				->setAttribute('title', _('Change type'))
		);

		if ($this->add_post_js) {
			zbx_add_post_js($this->getPostJS());
		}

		return $node->toString(true);
	}
}
