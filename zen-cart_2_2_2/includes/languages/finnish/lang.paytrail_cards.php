<?php
// Paytrail saved cards page (My account)
$define = include __DIR__ . '/modules/payment/lang.paytrail.php';
return array_merge($define, [
	'NAVBAR_TITLE' => 'Tallennetut korttini',
	'HEADING_TITLE' => 'Tallennetut maksukorttini',
	'TEXT_PAYTRAIL_CARDS_INFO' => 'Korttisi on tallennettu turvallisesti Paytrailiin. Kauppa ei näe eikä tallenna korttinumeroasi.',
	'TEXT_PAYTRAIL_NO_CARDS' => 'Sinulla ei ole tallennettuja kortteja.',
	'TEXT_PAYTRAIL_CARD_ADDED' => 'Kortti tallennettiin.',
	'TEXT_PAYTRAIL_CARD_ADD_FAILED' => 'Korttia ei voitu tallentaa. Yritä uudelleen.',
	'TEXT_PAYTRAIL_CARD_ADD_CANCEL' => 'Kortin lisääminen peruttiin.',
	'TEXT_PAYTRAIL_CARD_DELETED' => 'Kortti poistettiin.',
	'TEXT_PAYTRAIL_CARD_DISABLED' => 'Korttien tallennus ei ole käytössä tässä kaupassa.',
	'TEXT_PAYTRAIL_CARD_DELETE' => 'Poista',
	'TEXT_PAYTRAIL_CARD_DELETE_CONFIRM' => 'Poistetaanko kortti?',
	'TEXT_PAYTRAIL_CARD_EXPIRES' => 'Voimassa',
]);
