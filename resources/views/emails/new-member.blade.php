@extends('emails.layout.default')

@section('heading')
GenCC Member Notification
@endsection

@section('content')
Greetings {{ $user->name }},<br/><br/>
You are receiving this email because an account has been set up on the GenCC Submission Portal in your name.
Your login credentials are listed below:
<br />
<br />
<div style="margin-left: 32px;">
	Username:  {{ $user->email }}
	<br />
	Password:	{{ $pw }}
</div>
	<br />
	<div style="margin-bottom: 6px;">You can change your password after login via from the Profile Page.  
	The Progile Page is accessible from the navigaion menu in the upper right corner.</div>
	<br />
	Click <a href="{{ config('app.url') }}">here</a> to log into the GenCC Submission Portal

@endsection

@section('boilerplate')
	<strong>About GenCC</strong><br/>
	The Gene Curation Coalition (GenCC) is a global effort to harmonize gene-level resources and to facilitate 
	the consistent assessment of genes that have been reported in association with disease.
</br></br>
To learn more about GenCC, visit <a href="https://thegencc.org">thegencc.org</a>
@endsection