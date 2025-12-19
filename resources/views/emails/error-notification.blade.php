@extends('emails.layout.default')

@section('heading')
GenCC Job Status Notification
@endsection

@section('content')
Greetings {{ $job->user->name }},<br/><br/>
You are receiving this email because one or more submissions have errors preventing their publishing to the GenCC database.
<br />
<br />
	<center>Title</center>
	<br /><br />
	<hr />
	<br />
	<div style="margin-bottom: 6px;"><strong>The following submissions have have errors:</strong></div>
	@foreach ($submissions as $submission)
	&nbsp;&nbsp; {{ $submission }}<br/>
	@endforeach
	<br /><br />
	<strong>Manage your submissions</strong><br />
	Manage your submissions through the <a href="{{ config('app.url') }}">GenCC Submission Portal.</a>

@endsection

@section('boilerplate')
	<strong>About GenCC - Clinical Genome Resource</strong><br/>
ClinGen is a National Institutes of Health (NIH)-funded resource dedicated to building an authoritative central
resource that defines the clinical relevance of genes and variants for use in precision medicine and research.
</br></br>
To learn more about GenCC, visit <a href="https://thegencc.org">thegencc.org</a>
@endsection