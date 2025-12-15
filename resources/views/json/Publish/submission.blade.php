{
	"action": "{{ $action_type }}", 
	"date": "{{ $timestamp }}",
	"token": "{{ $token }}",
	"job": "{{ $job->slug }}",
	"publish_date": "{{ $job->submission_date }}",
	"data":  {
				"type": "Submission",
				"submission_id": "{{ $submission->sid }}",
				"version_number": {{ $submission->version_number ?? 1 }},
				"submission_label": "{{ $submission->friendly }}",
				"local_key": "{{ $submission->local_key }}",
				"submitted": "{{ $submission->submission_date }}",
				"status": "{{ $submission->display_status }}",
				"gene": {
					"id": "{{ $submission->gene->hgnc_id }}",
					"symbol": "{{ $submission->gene->symbol }}"
				}, 
				"disease": {
					"id": "{{ $submission->submission_data->disease->id }}",
					"name": "{{ $submission->disease->name }}"
				},
				@if($submission->original_disease && $submission->original_disease->id !== $submission->disease->id)
				"original_disease": {
					"id": "{{ $submission->originalDisease->curie }}",
					"name": "{{ $submission->originalDisease->name }}"
				},
				@endif	 
				"moi": {
					"id": "{{ $submission->inheritance->curie }}",
					"name": "{{ $submission->inheritance->name }}"
				}, 
				"workflow": {
					@foreach ($submission->submission_data->workflow as $key => $value)
					@if(!$loop->first)
					,
					@endif
					"{{ $key }}": "{{ $value }}"
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
				"mechanism": {
					"id": "{{ $submission->mechanism->curie ?? null }}",
					"name": "{{ $submission->mechanism->name ?? null }}",
					"comments": {!! json_encode($submission->submission_data->mechanism->comments ?? null) !!}
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
					"display": {!! json_encode($submission->submission_data->notes->display ?? '') !!},
					"private": {!! json_encode($submission->submission_data->notes->private ?? '') !!}
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
					"description": {!! json_encode($submission->submission_data->version->description ?? '') !!}
				},
				"submitter": {
					"id": "{{ $submission->submitter->curie }}",
					"name": "{{ $submission->submitter->name }}"
				},
				"contributors": {
					"primary": {
						"id":  "{{ $submission->submission_data->contributors->primary->id ?? '' }}",
						"name": "{{ $submission->submission_data->contributors->primary->name ?? '' }}"
					}
				},
				"additional_information": [{ "key": "values" }]
			}
}