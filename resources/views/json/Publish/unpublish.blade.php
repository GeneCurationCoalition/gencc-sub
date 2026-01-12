{
	"date": "{{ $timestamp }}",
	"token": "{{ $token }}",
	"unpublish_date": "{{ $action->command->timestamp }}",
	"data":  {
				"type": "Submission",
				"submission_id": "{{ $action->command->submission_id }}",
				"submitter": {
					"id": "{{ $action->submitter->curie }}",
					"name": "{{ $action->submitter->name }}"
				},
				"local_key": "{{ $action->command->local_key }}"
			}
}