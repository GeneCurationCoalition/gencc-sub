{
	"date":  "{{ $timestamp }}", 
	"message":  "{{ $message }}",
	"jobs": [
		{
		  	"id": "{{ $id }}",
         	"message": "{{ $job_message }}",
		  	"status_code": 20,
			"data": [
			   {
				"sid": "{{ $sid }}",
				"message": "{{ $submission_message }}",
				"status_code": 200
			   }
			]
		}
	]
}