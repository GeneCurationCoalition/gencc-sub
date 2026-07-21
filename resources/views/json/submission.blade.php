{
	"action": "create", 
	"date": "{{ $job->created_at }}",
	"submitter": {
		"id": "{{ $job->gencc_submitter_id }}",
		"name": "{{ $job->submitter_organization_name }}"
	},
	"data": [
	@foreach ($job->data as $d)	
	{
		"action": "{{ $d->action }}",
		"type": "Reserved",
		"submission_id": "{{ $d->submission_id }}",
		"submission_label": "{{ $d->submission_label }}",
		"local_key": "{{ $d->local_key }}",
		"gene": {
			"id": "{{ $d->hgnc_id }}",
			"symbol": "{{ $d->gene_symbol }}"
		}, 
		"disease": {
			"id": "{{ $d->mondo_id }}",
			"name": "{{ $d->disease_name }}"
		},	 
		"moi": {
			"id": "{{ $d->hp_id }}",
			"name": "{{ $d->moi_name }}"
		},
		"report": {
			"display_date": "{{ $d->report_date }}",
			"ext_url": "{{ $d->report_url }}"
		},
		"classification": {
			"id": "{{ $d->gencc_classification_id }}",
			"name": "{{ $d->gencc_classification_name }}"
		},
		"mechanism": {
			"id": "{{ $d->gencc_mechanism_id }}",
			"name": "{{ $d->gencc_mechanism_name }}",
			"comments": "{{ $d->gencc_mechanism_comment }}"
		},
		"criteria": {
			"name": "{{ $d->criteria_name }}",
			"url": "{{ $d->criteria_url }}"
		},
		"evidence": [
			@foreach ($d->evidence_items as $key => $evidence_item)
			{ "pmid": "{{ $evidence_item }}" }
			@if (!$loop->last)
			,
			@endif
			@endforeach
		],
		"notes": { 
			"display": "{{ $d->notes_display }}",
			"private": "{{ $d->notes_private }} "
		},
		"version": { 
			"display": "{{ $d->version_display }}",
			"internal": "{{ $d->version_internal }}",
			"reasons": [
				@if (is_array($d->reason_codes))
					@foreach($d->reason_codes as $reason_code)
						"{{ $reason_code }}"
						@if (!$loop->last)
						,
						@endif
					@endforeach
				@endif
				],
			"description": "{{ $d->version_description }}"
		},
		"contributors": {
			"primary": {
				"id":  "{{ $d->primary_contributor_id }}",
				"name": "{{ $d->primary_contributor_group_name }}"
			}
		},
		"additional_information": [{ "key": "values" }]
	}
	@if (!$loop->last)
	,
	@endif
	@endforeach
	]
}
