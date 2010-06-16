<? $url = $_GET['q']; ?>

<html>
	<head>
		<meta name="robots" content="noindex,nofollow">
		<script>window.location='<? echo $url; ?>';</script>
		<meta http-equiv="refresh" content="0; url=<? echo $url; ?>">
	</head>
	<body>
		<div style="padding: 30px; text-align: center;">
			Page Stuck? <a href="<? echo $url; ?>">Click Here</a>.
		</div>
	</body> 
</html> 