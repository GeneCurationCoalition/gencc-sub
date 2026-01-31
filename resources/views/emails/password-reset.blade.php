@extends('emails.layout.default')

@section('heading')
GenCC Password Reset
@endsection

@section('content')
Greetings {{ $user->name }},<br/><br/>
An administrator has reset your password for the GenCC Submission Portal.
Your new temporary login credentials are listed below:
<br />
<br />
<div style="margin-left: 32px;">
	Username:  {{ $user->email }}
	<br />
	Password:	{{ $pw }}
</div>
<br />
<div style="margin-bottom: 6px;">You will be required to change your password after logging in.</div>
<br />
Click <a href="{{ config('app.url') }}">here</a> to log into the GenCC Submission Portal
@endsection

@section('boilerplate')
	<strong>About GenCC</strong><br/>
	The Gene Curation Coalition (GenCC) is a global effort to harmonize gene-level resources and to facilitate
	the consistent assessment of genes that have been reported in association with disease.
<br/><br/>
To learn more about GenCC, visit <a href="https://thegencc.org">thegencc.org</a>
@endsection
