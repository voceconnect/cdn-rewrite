<?php
/*  Copyright 2010 Chris Scott (cscott@voceconnect.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (!class_exists('Voce_Settings')) {
	class Voce_Settings {

		private $page_name;
		private $option_name;
		private $page;
		private $section;

		public function __construct($option_group, $option_name, $sanitize_callback = '') {
			register_setting($option_group, $option_name, $sanitize_callback);
			$this->option_name = $option_name;
		}

		public function add_section($name, $title, $page, $callback = null) {
			// use blank output to allow not setting a callback if no output is desired between heading and fields
			$callback = (null === $callback) ? create_function('', 'echo \'\';') : $callback;

			add_settings_section($name, $title, $callback, $page);
			$this->section = $name;
			$this->page = $page;
			return $this;
		}

		public function add_field($id, $label, $type, $args = null, $page = null, $section = null) {
			$section = (null === $section) ? $this->section : $section;
			$page = (null === $page) ? $this->page : $page;
			$this->add_settings_field(
				$id,
				$label,
				$type,
				$page,
				$section,
				$args
			);
		}


		/**
		 * helper to use the settings API to add a field
		 *
		 * @param string $id the HTML ID for the field
		 * @param string $label the label tag text
		 * @param string $display_callback the function to use for the field display
		 * @param string $extra_args extra args to pass to the callback
		 * @return void
		 */
		public function add_settings_field($id, $label, $display_callback, $page, $section, $extra_args = null) {

			$args = array(
				'id' => $id,
				'label_for' => $id
			);

			if (is_array($extra_args)) {
				$args += $extra_args;
			}

			add_settings_field(
				$id,
				$label,
				array(&$this, $display_callback),
				$page,
				$section,
				$args
			);
		}


		/**
		 * checkbox callback for settings API
		 *
		 * @param string $args
		 * @return string checkbox
		 */
		public function field_checkbox($args) {
			$options = get_option($this->option_name);

			$defaults = array('prepend_field' => '', 'append_field' => '', 'class' => '');
			$args = wp_parse_args($args, $defaults);
			extract($args);

			$description = ($description) ? sprintf('<p><span class="description">%s</span></p>', $description) : '';

			$checked = checked($options[$id], '1', false);

			echo sprintf(
				"%s<input id='%s' name='{$this->option_name}[%s]' type='checkbox' value='1' %s class='%s' />%s%s",
				$prepend_field,
				$id,
				$id,
				$checked,
				$class,
				$append_field,
				$description
			);
		}

		/**
		 * input type text callback for settings API
		 *
		 * @param string $args
		 * @return string input
		 */
		public function field_input($args) {
			$options = get_option($this->option_name);

			$defaults = array('prepend_field' => '', 'append_field' => '', 'class' => 'regular-text', 'type' => 'text');
			$args = wp_parse_args($args, $defaults);
			extract($args);

			$description = (isset($description) && $description) ? sprintf('<p><span class="description">%s</span></p>', $description) : '';

			if (!isset($value)) {
				if (isset($options[$id])) {
					$value = $options[$id];
				} else {
					$value = '';
				}
			}

			echo sprintf(
				"%s<input id='%s' name='{$this->option_name}[%s]' type='%s' class='%s' value='%s' />%s%s",
				$prepend_field,
				$id,
				$id,
				$type,
				$class,
				esc_attr($value),
				$append_field,
				$description
			);
		}

		/**
		 * textarea callback for settings API
		 *
		 * @param string $args
		 * @return string textarea
		 */
		public function field_textarea($args) {
			$options = get_option($this->option_name);
			$id = $args['id'];

			$defaults = array('prepend_field' => '', 'append_field' => '', 'columns' => 40, 'rows' => 8, 'class' => 'large-text');
			$args = wp_parse_args($args, $defaults);
			extract($args);

			$description = (isset($description) && $description) ? sprintf('<p><span class="description">%s</span></p>', $description) : '';

			echo sprintf(
				"%s<textarea id='%s' name='{$this->option_name}[%s]' columns='%s' rows='%s' class='%s' />%s</textarea>%s%s",
				$prepend_field,
				$id,
				$id,
				$columns,
				$rows,
				$class,
				esc_attr($options[$id]),
				$append_field,
				$description
			);
		}

		public function field_radio($args) {
			$options = get_option($this->option_name);
			$id = $args['id'];

			$defaults = array('type' => 'radio', 'class' => '');
			$args = wp_parse_args($args, $defaults);
			extract($args);

			if (!$items) {
				return;
			}

			echo '<fieldset><p>';
			foreach ($items as $item) {
				if (!empty($options[$id])) {
					$checked = checked($options[$id], $item['value'], false);
				} else {
					$checked = false;
				}

				echo sprintf(
					"<label> <input id='%s' name='{$this->option_name}[%s]' type='%s', class='%s' value='%s' %s /> %s</label><br />",
					$id,
					$id,
					$type,
					$class,
					esc_attr($item['value']),
					$checked,
					esc_html($item['text'])
				);
			}
			echo '</fieldset></p>';

			$description = (isset($description) && $description) ? sprintf('<p><span class="description">%s</span></p>', $description) : '';
			echo $description;
		}
	}
}