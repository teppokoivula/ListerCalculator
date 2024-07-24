<?php namespace ProcessWire;

class ListerCalculator extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Lister Calculator',
			'summary' => 'Calculates sums of fields in Lister results',
			'version' => '0.0.7',
			'author' => 'Teppo Koivula',
			'icon' => 'calculator',
			'requires' => 'ProcessWire>=3.0.123',
			'autoload' => 'template=admin',
		];
	}

	public function getModuleConfigInputfields(array $data) {

		$inputfields = $this->wire(new InputfieldWrapper());

		$listers_and_fields = $this->wire(new InputfieldTextarea());
		$listers_and_fields->attr('name', 'listers_and_fields');
		$listers_and_fields->label = __('Listers and fields');
		$listers_and_fields->description = __('Enter lister names and field names, e.g. "payments=amount"');
		$listers_and_fields->notes = __('One lister and field combination per line');
		$listers_and_fields->setAttribute('rows', 10);
		$listers_and_fields->value = $data['listers_and_fields'] ?? '';
		$inputfields->add($listers_and_fields);

		return $inputfields;
	}

	public function init() {
		$this->addHookAfter('ProcessPageLister::renderResults', $this, 'renderResults');
	}

	protected function renderResults(HookEvent $event) {

		// parse listers and fields into an associative array
		$listers_and_fields = $this->getListersAndFields();
		if (empty($listers_and_fields)) return;

		// get lister and make sure it is one we are interested in
		$lister_page = $event->object->page;
		if (!in_array($lister_page->name, array_keys($listers_and_fields))) return;

		// get selector
		$selector = $event->object->getSelector();
		if (empty($selector)) return;

		// remove any blank items from selector (e.g. title= or title!= or title%=)
		$selector = $event->object->removeBlankSelectors($selector);
		if (empty($selector)) return;

		// no limits version of selector
		$no_limits_selector = empty($selector) || strpos($selector, 'limit=') === false
			? null
			: preg_replace('/\s+limit=\d+(?:, )?/', '', $selector);

		// fetch page IDs matching selector and no-limits selector
		$page_ids = $event->pages->findRaw($selector, 'id');
		if (empty($page_ids)) return;
		$no_limits_page_ids = $no_limits_selector
			? $event->pages->findRaw($no_limits_selector, 'id')
			: null;

		// calculate totals for each configured field
		$totals = [];
		foreach ($listers_and_fields as $lister_name => $field_names) {
			if ($lister_name !== $lister_page->name) continue;
			foreach ($field_names as $field_name) {
				if (isset($totals[$field_name])) continue;
				$totals[$field_name] = $this->calculateTotals($page_ids, $no_limits_page_ids, $field_name);
			}
		}

		$event->return .= $this->renderTotals($totals);
	}

	/**
	 * Calculate totals for a field
	 *
	 * @param array $page_ids
	 * @param array|null $no_limits_page_ids
	 * @param string $field_name
	 * @return array
	 */
	protected function ___calculateTotals(array $page_ids, ?array $no_limits_page_ids, string $field_name): array {

		$totals = [];

		// one can never be too sure...
		$page_ids = array_filter($page_ids, 'is_numeric');
		if (empty($page_ids)) return $totals;
		if (!empty($no_limits_page_ids)) {
			$no_limits_page_ids = array_filter($no_limits_page_ids, 'is_numeric');
			if (empty($no_limits_page_ids)) {
				$no_limits_page_ids = null;
			}
		}

		// table, data column, and ID column names
		$table_name = null;
		$data_column = 'data';
		$id_column = 'pages_id';

		// just in case support a few page properties
		if (in_array(strtolower($field_name), [
			'id',
			'name',
			'status',
			'created',
			'modified',
		])) {
			$table_name = 'pages';
			$data_column = strtolower($field_name);
			$id_column = 'id';
		}

		if ($table_name === null) {

			// get field object
			$field = wire()->fields->get($field_name);
			if (!$field) return $totals;

			// get table name
			$table_name = $field->getTable();
		}

		// calculate total amount for selector
		$query = wire()->database->query('
			SELECT SUM(`' . $data_column . '`) AS `value`
			FROM `' . $table_name . '`
			WHERE `' . $id_column . '` IN (
				' . implode(',', $page_ids) . '
			)
		');
		$totals['value'] = $query->fetchColumn() ?: 0;

		// calculate total amount for no-limits selector
		$totals['no_limits_value'] = null;
		if (!empty($no_limits_page_ids) && $totals['value']) {
			$query = wire()->database->query('
				SELECT SUM(`' . $data_column . '`) AS `value`
				FROM `' . $table_name . '`
				WHERE `' . $id_column . '` IN (
					' . implode(',', $no_limits_page_ids) . '
				)
			');
			$totals['no_limits_value'] = $query->fetchColumn();
		}

		return $totals;
	}

	/**
	 * Render totals
	 *
	 * @param array $totals
	 * @return string
	 */
	protected function ___renderTotals(array $totals): string {

		$out = '';

		foreach ($totals as $field_name => $total) {

			if (empty($total['value'])) continue;

			$field = wire()->fields->get($field_name);
			if (!$field) {
				$field = (object) [
					'label' => $field_name,
				];
			}

			$out .=
				'<div class="uk-margin-bottom uk-margin-small-right uk-badge" style="padding: 1.25rem">'
				. '<span uk-icon="icon: info" class="uk-margin-small-right"></span> '
				. sprintf(__('Sum of field "%s"'), $field->label) . ': '
				. $total['value']
				. (
					$total['no_limits_value'] !== null && $total['no_limits_value'] != $total['value']
						? ' ' . __('of') . ' ' . $total['no_limits_value']
						: ''
				)
				. '</div>';
		}

		return $out;
	}

	/**
	 * Get listers and fielsd from module config
	 *
	 * @return array
	 */
	protected function ___getListersAndFields(): array {

		$configured_value = $this->get('listers_and_fields');
		if (empty($configured_value)) return [];

		$listers_and_fields = explode("\n", $configured_value);
		$listers_and_fields = array_map('trim', $listers_and_fields);
		$listers_and_fields = array_filter($listers_and_fields);
		$listers_and_fields = array_map(function($line) {
			return explode('=', $line);
		}, $listers_and_fields);
		$listers_and_fields = array_filter($listers_and_fields, function($line) {
			return count($line) === 2;
		});

		// convert to associative array where key is lister name and value is an array of field names
		$listers_and_fields = array_reduce($listers_and_fields, function($carry, $item) {
			$carry[$item[0]][] = $item[1];
			return $carry;
		}, []);

		return $listers_and_fields;
	}
}
