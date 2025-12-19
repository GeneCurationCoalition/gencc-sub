{
	"action": "status", 
	"type": "Job",
	"jid": "{{ $job->ident }}",
	"submitted": "{{ $job->created_at }}",
	"submitted_by": "{{ $job->user->clingen_id }}",
	"last_update": "{{ $job->updated_at }}",
	"message": {
		"errorCode": null,
		"severity": "info",
		"text": "Your GenCC submission job processing status is: {{ $job->display_status }} 
	  },
	"data" : [
	{
		"type": Submission",
		"sid": "{{ $submission->ident }}",
		"submitted": "{{ $submission->created_at }}",
		"last_update": "{{ $submission->updated_at }}",
		"status": "submitted"
		"gene": {
			"id": "{{ $submission->gene->hgnc_id }}",
			"symbol": "{{ $submission->gene->symbol }}"
		}, 
		"disease": {
			"id": "{{ $submission->disease->curie }}",
			"name": "{{ $submission->disease->name }}"
		},	 
		"moi": {
			"id": "{{ $submission->inheritance->curie }}",
			"name": "{{ $submission->inheritance->name }}"
		}, 
		"workflow": {
			@foreach ($submission->submission_data->workflow as $key => $value)
			"{{ $key }}": "{{ $value }}"
			@if (!$loop->last)
			,
			@endif
			@endforeach
		},
		"report": {
			"display_date": "{{ $submission->report_date }}",
			"ext_url": "{{ $submission->report_url }}"
		},
		"classification": {
			"id": "{{ $submission->classification->curie }}",
			"name": "{{ $submission->classification->name }}"
		},
		"criteria": {
			"name": "{{ $submission->submission_data->criteria->name }}",
			"url": "{{ $submission->submission_data->criteria->url }}"
		},
		"evidence": [
			@foreach ($submission->submission_data->evidence as $evidence_item)
			{ "pmid": "{{ $evidence_item->pmid}}" }
			@if (!$loop->last)
			,
			@endif
			@endforeach
		],
		"lumpsplit": { "key": "value" },
		"notes": { 
			"display": "{{ $submission->submission_data->notes->display }}",
			"private": "{{ $submission->submission_data->notes->private }} "
		},
		"version": { 
			"display": "{{ $submission->submission_data->version->display }}",
			"internal": "{{ $submission->submission_data->version->internal }}",
			"reasons": [
				@if (isset($submission->submission_data->reason_codes))
				@forelse($submission->submission_data->reason_codes as $reason_code)
					"{{ $reason_code }}"
					@if (!$loop->last)
					,
					@endif
				@endforeach
				@endif
				],
			"description": "{{ $submission->submission_data->version->description }}"
		},
		"submitter": {
			"id": "{{ $submission->submitter->curie }}",
			"name": "{{ $submission->submitter->name }}"
		},
		"contributors": {
			"primary": {
				"id":  "{{ $submission->submission_data->contributors->primary->id }}",
				"name": "{{ $submission->submission_data->contributors->primary->name }}"
			},
			"secondary": [
				@foreach ($submission->submission_data->contributors->secondary as $key => $value)
				{
			 		"id":  "{{ $key }}",
			 		"name": "{{ $value }}"
				}
					@if (!$loop->last)
					,
					@endif
				@endforeach
			]
		},
		"meta": { "key": "values" },
		"ancillary": { "key": "", "value": "" }
	}
	]
}