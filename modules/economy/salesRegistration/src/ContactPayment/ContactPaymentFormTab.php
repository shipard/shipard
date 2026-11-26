<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\ContactPayment;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Module\Economy\SalesRegistration\Evaluation\DocFactsLoader;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Záložka Evidence tržeb ve formuláři dokladu (#171 D8b, U16): zaškrtávátko
 * **Kontaktní platba** a čas platby u faktury vydané a zálohové faktury
 * vydané. Sloupce přidává hlavičce extension doplňku a ukládá je běžné
 * uložení formuláře; co smí projít, hlídá {@see ContactPaymentSaveHandler}.
 *
 * U kterých typů dokladů záložka vzniká, říkají data typu evidence
 * registrací V pořádku ({@see ContactPaymentPolicy}) — bez registrace
 * nevzniká vůbec. Způsob úhrady záložka neposuzuje (uživatel ho ve formuláři
 * teprve volí); příznak u úhrady, která ho nést nesmí, odmítne uložení.
 *
 * V Konceptu a V opravě se pole editují; na dokladu V pořádku je formulář
 * jen ke čtení a příznak nastavuje akce Kontaktní platba.
 */
class ContactPaymentFormTab extends ModuleFormTab
{
    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        $docType = (string) ($data['doc_type'] ?? '');
        if ($docType === '' || !$this->policy($context)->offersTab($docType)) {
            return null;
        }

        $t = Texts::of($context->config);

        return $this->tab($context, 'salesRegistration', $t->t('contactPayment.tab', 'Sales registration'), 'cash-register')
            ->section($t->t('contactPayment.section', 'Contact payment'))
                ->col()
                    ->content([[
                        'type' => 'hint',
                        'text' => $t->t(
                            'contactPayment.formHint',
                            'Tick when the customer paid an invoice issued for bank transfer on the spot — by QR code or by card on a terminal. The whole amount of the document is then registered as a sale once the document is confirmed. Do not use it for payments over the internet (payment method Payment gateway). The flag does not change the payment method or the accounting of the document.',
                        ),
                    ]])
                    ->checkbox(ContactPaymentSaveHandler::FLAG)
                    ->datetime(
                        ContactPaymentSaveHandler::PAID_AT,
                        hint: $t->t(
                            'contactPayment.formTimeHint',
                            'When the customer paid. Left empty, the time the document is confirmed applies.',
                        ),
                    )
            ->build();
    }

    /** Politika U14 nad registracemi V pořádku (přepsatelné v testech). */
    protected function policy(ModuleExtensionContext $context): ContactPaymentPolicy
    {
        return ContactPaymentPolicy::fromLoader(
            new DocFactsLoader($context->db->getDibiConnection(), $context->config, $context->dsConfig),
        );
    }
}
