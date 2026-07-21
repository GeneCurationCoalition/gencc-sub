{
	"action": "status", 
	"type": "Job",
	"jid": "{{ $job->slug }}",
	"submitted": "{{ $job->created_at }}",
	"submitteder" : "{{ $job->user->clingen_id }}",
	"last_update": "{{ $job->updated_at }}",
	"message": {
		"errorCode": null,
		"severity": "info",
		"text": "Your Gencc submission job processing status is: {{ $job->display_status }}"
	  },
	"data" : [
	@foreach ($submissions as $submission)
	@if(!$loop->first)
	,
	@endif
	{
		"type": "Submission",
		"submission_id": "{{ $submission->ident }}",
		"submission_label": "{{ $submission->label }}",
		"local_key": "{{ $submission->local_key }}",
		"submitted": "{{ $submission->created_at }}",
		"last_update": "{{ $submission->updated_at }}",
		"status": "{{ $submission->display_status }}"
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
		"mechanism": {
			"id": "{{ $submission->mechanism->curie ?? null }}",
			"name": "{{ $submission->mechanism->name ?? null }}"
			"comments": "{{ $submission->submission_data->mechanism->comments ?? '' }}"
		}
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
		},
		"meta": { "key": "values" },
		"additional_information": { "key": "", "value": "" }
	}
	@endforeach
	]
}
