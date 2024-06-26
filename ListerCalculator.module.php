<?php namespace ProcessWire;

class ListerCalculator extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo() {
		return [
			'title' => 'Lister Calculator',
			'summary' => 'Calculates sums of fields in Lister results',
			'version' => '0.0.3',
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
		foreach ($listers_and_fields as $lister_name => $field_name) {
			if ($lister_name !== $lister_page->name) continue;
			if (isset($totals[$field_name])) continue;
			$totals[$field_name] = $this->calculateTotals($page_ids, $no_limits_page_ids, $field_name);
		}

		$event->return .= $this->renderTotals($totals);
	}

	/**
	 * Calculate totals for a field
	 *
	 * @param array $page_ids
	 * @param array $no_limits_page_ids
	 * @param string $field_name
	 * @return array
	 */
	protected function ___calculateTotals(array $page_ids, ?array $no_limits_page_ids, string $field_name): array {

		$totals = [];

		// get field object
		$field = wire()->fields->get($field_name);
		if (!$field) return $totals;

		// calculate total amount for selector
		$query = wire()->database->query('
			SELECT SUM(`data`) AS `value`
			FROM `' . $field->getTable() . '`
			WHERE `pages_id` IN (
				' . implode(',', $page_ids) . '
			)
		');
		$totals['value'] = $query->fetchColumn() ?: 0;

		// calculate total amount for no-limits selector
		if (!empty($no_limits_page_ids) && $totals['value']) {
			if ($no_limits_page_ids) {
				$query = wire()->database->query('
					SELECT SUM(`data`) AS `value`
					FROM `' . $field->getTable() . '`
					WHERE `pages_id` IN (
						' . implode(',', $no_limits_page_ids) . '
					)
				');
				$totals['no_limits_value'] = $query->fetchColumn();
			}
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

		foreach ($totals as $field_name => $totals) {

			if (empty($totals['value'])) continue;

			$field = wire()->fields->get($field_name);
			if (!$field) continue;

			$out .=
				'<div class="uk-margin-bottom uk-margin-small-right uk-badge" style="padding: 1.25rem">'
				. '<span uk-icon="icon: info" class="uk-margin-small-right"></span> '
				. sprintf(__('Sum of field "%s"'), $field->label) . ': '
				. $totals['value']
				. (
					$totals['no_limits_value'] !== null && $totals['no_limits_value'] != $totals['total']
						? ' ' . __('of') . ' ' . $totals['no_limits_value']
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

		$listers_and_fields = $this->get('listers_and_fields');
		if (empty($listers_and_fields)) return [];

		$listers_and_fields = explode("\n", $listers_and_fields);
		$listers_and_fields = array_map('trim', $listers_and_fields);
		$listers_and_fields = array_filter($listers_and_fields);
		$listers_and_fields = array_map(function($line) {
			return explode('=', $line);
		}, $listers_and_fields);
		$listers_and_fields = array_filter($listers_and_fields, function($line) {
			return count($line) === 2;
		});
		$listers_and_fields = array_combine(array_column($listers_and_fields, 0), array_column($listers_and_fields, 1));

		return $listers_and_fields;
	}
}
