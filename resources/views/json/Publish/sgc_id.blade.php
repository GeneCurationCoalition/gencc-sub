{
	"action": "sgc_id",
	"date": "{{ $timestamp }}",
	"token": "{{ $token }}",
	"data":  {
		"type": "Submission",
		"search_row_id": "{!! json_encode($submission->submission_data->search_row_id ?? null) !!}",
		"submission_id": "{{ $submission->sid }}",
		"local_key": "{{ $submission->local_key ?? '' }}"
	}
}