<?php $url = $_GET['q']; ?>

<html>
	<head>
		<meta name="robots" content="noindex,nofollow">
		<script>window.location='<?php echo $url; ?>';</script>
		<meta http-equiv="refresh" content="0; url=<?php echo $url; ?>">
	</head>
	<body>
		<div style="padding: 30px; text-align: center;">
			Page Stuck? <a href="<?php echo $url; ?>">Click Here</a>.
		</div>
	</body> 
</html> 