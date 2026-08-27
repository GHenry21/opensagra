<?php

require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

if (isset($_POST['htmlContent'])) {
	$html = $_POST['htmlContent'];
	
	$dompdf = new Dompdf();
	$dompdf->loadHtml($html);
	$dompdf->setPaper('A4', 'portrait');
	$dompdf->render();
	
	$dompdf->stream('statistiche_vendite.pdf', ["Attachment" => 1]);
	echo "OK";
} else {
	echo "Nessun contenuto ricevuto";
}
?>
