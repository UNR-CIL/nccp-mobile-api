<?php
// Simple view for displaying data index
?>
<!DOCTYPE html>
<html lang="en">
<head>

	<meta charset="utf-8">
	<title>Welcome to NCCP Mobile</title>

</head>
<body>

<div id="main-content">
	Hello!
	<?php 
	if ( $mobile )
		echo "You're mobile!";
	else
		echo "You're NOT mobile!";
	?>
</div>

</body>
</html>