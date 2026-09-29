{
	"date":  "{{ $timestamp }}", 
	"message":  "{{ $message }}",
    "warnings": {!! json_encode($warnings ?? []) !!},
	"jobs": [
		{
		  	"id": "{{ $id }}",
         	"message": "{{ $message }}",
		  	"status_code": 200
		}
	]
}
