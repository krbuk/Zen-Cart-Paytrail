<?php
// Paytrail saved cards page (My account)
$define = include __DIR__ . '/modules/payment/lang.paytrail.php';
return array_merge($define, [
	'NAVBAR_TITLE' => 'My saved cards',
	'HEADING_TITLE' => 'My saved payment cards',
	'TEXT_PAYTRAIL_CARDS_INFO' => 'Your cards are stored securely at Paytrail. The store never sees or saves your card number.',
	'TEXT_PAYTRAIL_NO_CARDS' => 'You have no saved cards.',
	'TEXT_PAYTRAIL_CARD_ADDED' => 'The card was saved.',
	'TEXT_PAYTRAIL_CARD_ADD_FAILED' => 'The card could not be saved. Please try again.',
	'TEXT_PAYTRAIL_CARD_ADD_CANCEL' => 'Adding the card was cancelled.',
	'TEXT_PAYTRAIL_CARD_DELETED' => 'The card was removed.',
	'TEXT_PAYTRAIL_CARD_DISABLED' => 'Saving cards is not in use in this store.',
	'TEXT_PAYTRAIL_CARD_DELETE' => 'Remove',
	'TEXT_PAYTRAIL_CARD_DELETE_CONFIRM' => 'Remove this card?',
	'TEXT_PAYTRAIL_CARD_EXPIRES' => 'Expires',
]);
