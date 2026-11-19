<?php
/**
 * Template: My saved Paytrail cards
 * Copy to includes/templates/YOUR_TEMPLATE/templates/ to customize.
 * @version $Id: Nida Verkkopalvelu (www.nida.fi) / krbuk 2026 Nov 19 v5.8.0
 */
?>
<div class="centerColumn" id="paytrailCardsDefault">
    <h1 id="paytrailCardsDefaultHeading"><?php echo HEADING_TITLE; ?></h1>

    <?php if ($messageStack->size('paytrail_cards') > 0) echo $messageStack->output('paytrail_cards'); ?>

    <?php if (!$paytrail_enabled) { ?>
        <p><?php echo TEXT_PAYTRAIL_CARD_DISABLED; ?></p>
    <?php } else { ?>
        <p><?php echo TEXT_PAYTRAIL_CARDS_INFO; ?></p>

        <?php if (empty($paytrail_cards)) { ?>
            <p><strong><?php echo TEXT_PAYTRAIL_NO_CARDS; ?></strong></p>
        <?php } else { ?>
            <table class="table" style="width:100%; max-width:600px">
                <?php foreach ($paytrail_cards as $card) { ?>
                <tr>
                    <td><?php echo zen_output_string_protected(trim($card['card_type'] . ' **** ' . $card['partial_pan'])); ?></td>
                    <td><?php if (!empty($card['expire_month'])) echo TEXT_PAYTRAIL_CARD_EXPIRES . ' ' . zen_output_string_protected(str_pad($card['expire_month'], 2, '0', STR_PAD_LEFT) . '/' . substr($card['expire_year'], -2)); ?></td>
                    <td style="text-align:right">
                        <?php echo zen_draw_form('paytrail_card_delete_' . (int)$card['id'], zen_href_link('paytrail_cards', '', 'SSL'), 'post', 'onsubmit="return confirm(\'' . addslashes(TEXT_PAYTRAIL_CARD_DELETE_CONFIRM) . '\');"'); ?>
                            <?php echo zen_draw_hidden_field('action', 'delete') . zen_draw_hidden_field('card_id', (int)$card['id']); ?>
                            <button type="submit" class="btn btn-outline-danger btn-sm"><?php echo TEXT_PAYTRAIL_CARD_DELETE; ?></button>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </table>
        <?php } ?>

        <div style="margin-top:15px"><?php echo $paytrail_add_card_form; ?></div>
    <?php } ?>

    <div class="buttonRow back" style="margin-top:20px">
        <?php echo zen_back_link() . zen_image_button(BUTTON_IMAGE_BACK, BUTTON_BACK_ALT) . '</a>'; ?>
    </div>
</div>
