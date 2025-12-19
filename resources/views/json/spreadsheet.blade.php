{!! json_encode([
	'type' => 'Reserved',
	'submission_id' => $d->submission_id ?? '',
	'submission_label' => $d->submission_label ?? '',
	'local_key' => $d->local_key ?? '',
	'gene' => [
		'id' => $d->hgnc_id ?? '',
		'symbol' => $d->gene_symbol ?? ''
	],
	'disease' => [
		'id' => $d->mondo_id ?? '',
		'name' => $d->disease_name ?? ''
	],
	'moi' => [
		'id' => $d->hp_id ?? '',
		'name' => $d->moi_name ?? ''
	],
	'workflow' => (object) ($d->workflow_dates ?? []),
	'report' => [
		'display_date' => $d->report_date ?? '',
		'ext_url' => $d->report_url ?? ''
	],
	'classification' => [
		'id' => $d->gencc_classification_id ?? '',
		'name' => $d->gencc_classification_name ?? ''
	],
	'mechanism' => [
		'id' => $d->gencc_mechanism_id ?? '',
		'name' => $d->gencc_mechanism_name ?? '',
		'comment' => $d->gencc_mechanism_comment ?? ''
	],
	'criteria' => [
		'name' => $d->criteria_name ?? '',
		'url' => $d->criteria_url ?? ''
	],
	'evidence' => collect($d->evidence_items ?? [])->map(fn($item) => ['pmid' => $item])->toArray(),
	'notes' => [
		'display' => $d->notes_display ?? '',
		'private' => ($d->notes_private ?? '') . ' '
	],
	'version' => [
		'display' => $d->version_display ?? '',
		'internal' => $d->version_internal ?? '',
		'reasons' => $d->reason_codes ?? [],
		'description' => $d->version_description ?? ''
	],
	'contributors' => [
		'primary' => [
			'id' => $d->primary_contributor_id ?? '',
			'name' => $d->primary_contributor_group_name ?? ''
		]
	],
	'additional_information' => [['key' => 'values']]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}