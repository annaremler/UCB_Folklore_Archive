<?php
error_reporting(E_ALL ^ E_NOTICE);
include_once('lib.php');
include_once('mini/cms.php');

$cms->js[] = 'http://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.min.js';
$cms->js[] = "js/jquery.ui.core.js";
$cms->js[] = "js/jquery.ui.widget.js";
$cms->js[] = "js/jquery.ui.datepicker.js";
//$cms->js[] = "js/jquery-latest.js";// include the table sorter dose not show the arrow
$cms->js[] = "js/kfl.js";
$cms->js[] = "main.js";
$cms->css[] = "css/jquery.ui.all.css";
$cms->css[] = "css/layout.css";
$cms->css[] = "css/menu.css";
$content = $cms->content();
$menu = $cms->content('menu');
header('Content-Type: text/html; charset=utf-8'); 
?>
<!DOCTYPE HTML>
<html>
<head>
    <meta http-equiv="X-UA-Compatible" content="IE=EDGE" />
	<?php echo $cms->head(); ?> 
</head>
<body>
<?php
if ($_SERVER['SERVER_NAME']=='localhost'){
	echo "<h1>This is localhost site</h1>";
}
?>
<div id="wrapper">

	<div id="topWrapper"></div>

	<table id="mainArea">
	<tr>
		<td class="leftcolumn">
			<?php echo $menu; ?>
		</td>
		<td class="rightcolumn">
			<div id="contentArea">
				<?php echo $content; ?>
			</div>
		</td>
	</tr>
	</table>

	<div id="footer">
		<a href="http://www.universityofcalifornia.edu/">University of California</a> Copyright &copy; <?php echo date("Y"); ?> UC Regents&nbsp;&nbsp;|&nbsp;&nbsp;
		<a href="http://cdh.ucla.edu/ticket" target="_blank">Web Support</a>&nbsp;&nbsp;|&nbsp;&nbsp;
		<a href="http://bitbucket.org/uclacdh/kfl-map-search" target="_blank">Open Source Code</a>
	</div>

</div>


</body>
</html>
