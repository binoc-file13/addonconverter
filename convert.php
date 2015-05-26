<?php
require "app/Init.php";
require "app/functions.php";

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
	header("Location: http://" . $_SERVER['HTTP_HOST'] . "/");
}

emptyXPICache();

try {
	$url = isset($_POST['url']) ? trim($_POST['url']) : null;
	
	$uploadFileName = (empty($_FILES) || empty($_FILES['xpi']['name'])) ? '' : $_FILES['xpi']['name'];
	
	if (!$uploadFileName && !$url) {
		throw new Exception("No file to process");
	}
	
	$dirPart = uniqid("", true);
	$tmpDir = "tmp/convert/$dirPart";
	mkdir($tmpDir);
	
	$tmpSourceDir = "$tmpDir/source";
	$tmpDestDir = "$tmpDir/dest";
	
	mkdir($tmpSourceDir);
	mkdir($tmpDestDir);
	
	$tmpFile = "$tmpSourceDir/$uploadFileName";
	$maxFileSize = 16 * 1024 * 1024;
	
	$logger = new ConversionLogger;
	
	$logData = array();
	
	if (!empty($_POST['installButton'])) {
		$logData['button'] = 'inst';
	} elseif (!empty($_POST['detailsButton'])) {
		$logData['button'] = 'det';
	}
	
	if ($uploadFileName) {
		$logData += array(
			'url' => $uploadFileName,
			'file_length' => $_FILES['xpi']['size'],
		);
		
		$logger->log($logData);
		
		if (!@move_uploaded_file($_FILES['xpi']['tmp_name'], $tmpFile)) {
			throw new Exception("Error moving file to temporary folder");
		}
		
		if (filesize($tmpFile) > $maxFileSize) {
			unlink($tmpFile);
			$maxMB = round($maxFileSize / 1024 / 1024, 1);
			throw new Exception("Input file too large. Maximum $maxMB MB is allowed");
		}
		
	} elseif ($url) {
		$logData += array(
			'url' => $url,
		);
		
		$logger->log($logData);
		
		$ag = new AMOGrabber($maxFileSize);
		$tmpFile = $ag->fetch($url, $tmpSourceDir);
	}
	
	
	$startTime = microtime(true);
	
	$conv = new AddOnConverter($tmpFile);
	
	// pass options from form to converter object
	$conv->maxVersionStr = (string) substr(trim(@$_POST['maxVersion']), 0, 10);
	$conv->appendName = (string) substr(trim(@$_POST['appendName']), 0, 500);
	$conv->convertChromeUrls = !empty($_POST['convertChromeUrls']);
	$conv->convertChromeURLsInExt = array();
	
	if (isset($_POST['convertChromeExtensions'])
		&& is_array($_POST['convertChromeExtensions'])
		&& count($_POST['convertChromeExtensions'] < 30)
	) {
		$conv->convertChromeURLsInExt = $_POST['convertChromeExtensions'];
	}
	
	$conv->convertManifest = !empty($_POST['convertManifest']);
	$conv->convertPageInfoChrome = !empty($_POST['convertPageInfoChrome']);
	$conv->xulIds = !empty($_POST['xulIds']);
	$conv->jsShortcuts = !empty($_POST['jsShortcuts']);
	$conv->replaceEntities = !empty($_POST['replaceEntities']);
	$conv->jsKeywords = !empty($_POST['jsKeywords']);
	
	$destFile = $conv->convert($tmpDestDir);
	$result = $conv->getLogMessages();
	$warnings = $conv->getWarnings();
	
	$duration = number_format(microtime(true) - $startTime, 2, '.', '');
	
	unlink($tmpFile);
	
	$addOnName = $conv->getAddOnName();
	$logger->log(array(
		"addon_name" => trim($addOnName['name'] . " " . $addOnName['version']),
		"duration" => $duration,
	));
	
	
} catch (Exception $ex) {
	$error = $ex->getMessage();
	include "index.php";
	exit;
}


if (!empty($_POST['installButton'])) {
	// install directly!
	header("HTTP/1.1 303 See Other");
	header("Location: http://$_SERVER[HTTP_HOST]/install.php?file=" . urlencode($destFile));
	exit;
}
?>

<? include "templates/header.php" ?>
<h1 class="addon-name">
	<?=  htmlspecialchars($addOnName['name']) ?>
	<span class="version"><?=  htmlspecialchars($addOnName['version']) ?></span>
</h1>

<h2>Conversion Results (click on file names to see changes):</h2>

<? if ($destFile): ?>
	<? if ($warnings): ?>
		<? foreach ($warnings as $warning): ?>
			<div class="warning"><strong>Warning!</strong> <?=$warning ?></div>
		<? endforeach;?>
	<? endif ?>
	<ol>
		<? foreach ($result as $file => $messages): ?>
		<li><?=makeLinkToDiff($file, $dirPart) ?>
			<ul>
				<? foreach ($messages as $msg): ?>
					<li><?=$msg; ?></li>
				<? endforeach ?>
			</ul>
		</li>
		<? endforeach ?>
	</ol>

	<h2>Your converted add-on is available here for download.</h2>
	<p style="font-size: 75%">Left-click to install, or right click -&gt; <em>Save Link Target As</em> to download:</p>
	
	<p>
		<a href="<?=htmlspecialchars($destFile) ?>" class="download"><?=htmlspecialchars(basename($destFile)) ?></a>
		&mdash;
		<span class="filesize">
			<?=round(filesize($destFile) / 1024) ?> KB
		</span>
	</p>


<? else: ?>
	<p>I didn't find anything to convert in this add-on.</p>
<? endif ?>

	<br>
	<hr>
	<p style="margin-top: 1em"><a href=".">« perform another conversion</a></p>

<? include "templates/footer.php" ?>
