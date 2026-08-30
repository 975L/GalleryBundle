<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Message\GalleryPrintOrderMessage;
use c975L\GalleryBundle\Service\GalleryCertificateService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\Translation\t;

/**
 * The orders a lab is printing, and the ones waiting for a human.
 *
 * Read far more often than edited: nearly everything here is written by the checkout or by the lab's own callbacks. The
 * two things an admin actually does are on the row - print the certificate of an art edition, and release the order to
 * the lab once it has been signed.
 */
class GalleryPrintOrderCrudController extends AbstractCrudController
{
    // The colours of the states, so what needs attention is read off the list without opening anything
    private const array STATE_BADGES = [
        GalleryPrintOrder::STATE_PENDING => 'warning',
        GalleryPrintOrder::STATE_SENT => 'info',
        GalleryPrintOrder::STATE_PRODUCING => 'primary',
        GalleryPrintOrder::STATE_SHIPPED => 'success',
        GalleryPrintOrder::STATE_CANCELLED => 'secondary',
        GalleryPrintOrder::STATE_FAILED => 'danger',
    ];

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryCertificateService $certificateService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return GalleryPrintOrder::class;
    }

    private function roleNeeded(): string
    {
        return (string) $this->configService->get('site-role-editor');
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.print_order', [], 'gallery'))
            ->setEntityLabelInPlural(t('label.print_orders', [], 'gallery'))
            ->setEntityPermission($this->roleNeeded())
            // Newest first, an order being acted on within a day or two of arriving
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined()
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $certificate = Action::new('printCertificates', t('action.print_certificates', [], 'gallery'), 'fa fa-certificate')
            ->linkToCrudAction('printCertificates')
            // Nothing to certify on an open edition, so the action is not offered there rather than being offered and refused
            ->displayIf(static fn (GalleryPrintOrder $order): bool => $order->hasLimitedEdition())
        ;

        $release = Action::new('releaseToLab', t('action.release_to_lab', [], 'gallery'), 'fa fa-paper-plane')
            ->linkToCrudAction('releaseToLab')
            ->displayIf(static fn (GalleryPrintOrder $order): bool => $order->needsAttention())
        ;

        return $actions
            ->add(Crud::PAGE_INDEX, $certificate)
            ->add(Crud::PAGE_INDEX, $release)
            ->add(Crud::PAGE_DETAIL, $certificate)
            ->add(Crud::PAGE_DETAIL, $release)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // An order is what happened, not something to compose: it is written by the checkout and by the lab, and an admin correcting it by hand would be correcting the record of a sale
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->setPermission(Action::DETAIL, $this->roleNeeded())
            ->setPermission('printCertificates', $this->roleNeeded())
            ->setPermission('releaseToLab', $this->roleNeeded())
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', t('label.print_order_date', [], 'gallery'));

        yield AssociationField::new('basket', t('label.print_order_basket', [], 'gallery'));

        yield ChoiceField::new('state', t('label.print_order_state', [], 'gallery'))
            ->setChoices(array_combine(array_keys(self::STATE_BADGES), array_keys(self::STATE_BADGES)))
            ->renderAsBadges(self::STATE_BADGES)
        ;

        yield TextField::new('provider', t('label.print_order_provider', [], 'gallery'))->hideOnIndex();

        yield TextField::new('reference', t('label.print_order_reference', [], 'gallery'));

        yield AssociationField::new('copies', t('label.print_order_copies', [], 'gallery'))->onlyOnDetail();

        yield DateTimeField::new('sentAt', t('label.print_order_sent_at', [], 'gallery'))->hideOnIndex();
        yield DateTimeField::new('shippedAt', t('label.print_order_shipped_at', [], 'gallery'))->hideOnIndex();

        // The lab's own words about a refusal, which are nearly always about the file or the sku - and which say which of the two to go and fix
        yield TextareaField::new('lastError', t('label.print_order_last_error', [], 'gallery'))
            ->onlyOnDetail()
        ;
    }

    // Hands the order back to the queue that talks to the lab. The same path a paid open edition takes on its own, which is why nothing is sent from here directly: one road to the lab, one place a failure is recorded
    public function releaseToLab(AdminContext $context): RedirectResponse
    {
        $order = $context->getEntity()->getInstance();

        if ($order instanceof GalleryPrintOrder && $order->needsAttention()) {
            // Back to pending first: a failed order retried has to leave its previous refusal behind, or the screen goes on showing an error about a sending that has since been asked for again
            $order->setState(GalleryPrintOrder::STATE_PENDING);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new GalleryPrintOrderMessage((int) $order->getId()));
            $this->addFlash('success', t('label.print_order_released', [], 'gallery'));
        }

        return $this->redirect($this->indexUrl());
    }

    // The listing every action comes back to, whether it ran or was refused
    private function indexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }

    // The certificates of an order, as one pdf per numbered copy would be one download per copy - an order of three prints is three sheets to sign, and they are wanted together
    public function printCertificates(AdminContext $context): Response
    {
        $order = $context->getEntity()->getInstance();

        if (!$order instanceof GalleryPrintOrder) {
            return $this->redirect($this->indexUrl());
        }

        $copies = array_values(array_filter(
            $order->getCopies()->toArray(),
            static fn (GalleryPrintCopy $copy): bool => null !== $copy->getNumber(),
        ));

        $first = $copies[0] ?? null;
        $pdf = $this->certificateService->renderMany($copies);

        if (null === $first || null === $pdf) {
            $this->addFlash('warning', t('label.print_order_no_certificate', [], 'gallery'));

            return $this->redirect($this->indexUrl());
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->certificateService->getFilename($first)),
        ]);
    }
}
