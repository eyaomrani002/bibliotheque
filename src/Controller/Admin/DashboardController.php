<?php

namespace App\Controller\Admin;

use App\Entity\Auteur;
use App\Entity\Categorie;
use App\Entity\Editeur;
use App\Entity\Livre;
use App\Entity\User;
use App\Entity\Contact;
use App\Entity\Emprunt;
use App\Entity\Avis;
use App\Entity\Wishlist;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\LivreRepository;
use App\Repository\UserRepository;
use App\Repository\EmpruntRepository;
use App\Repository\ContactRepository;
use App\Repository\AvisRepository;
use App\Repository\WishlistRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use Symfony\Component\Security\Core\User\UserInterface;
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private AdminUrlGenerator $adminUrlGenerator,
        private LivreRepository $livreRepository,
        private UserRepository $userRepository,
        private EmpruntRepository $empruntRepository,
        private ContactRepository $contactRepository,
        private AvisRepository $avisRepository,
        private WishlistRepository $wishlistRepository
    ) {}

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        // Redirection vers le tableau de bord personnalisé ou les livres
        return $this->render('admin/dashboard/custom.html.twig', [
            'stats' => $this->getStats(),
            'recentActivity' => $this->getRecentActivity(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('📚 Biblio - Administration')
            ->setFaviconPath('favicon.ico')
            ->setTextDirection('ltr')
            ->renderContentMaximized()
            ->generateRelativeUrls()
            ->setLocales(['fr', 'en']) // Langues disponibles
            ->disableUrlSignatures(); // Désactive les signatures d'URL pour plus de simplicité
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('📊 Tableau de bord', 'fa fa-home');
        
        // Section principale
        yield MenuItem::section('📚 Gestion de la Bibliothèque', 'fas fa-book');
        yield MenuItem::linkToCrud('📖 Livres', 'fas fa-book', Livre::class)
            ->setBadge($this->livreRepository->count([]), 'primary');
        yield MenuItem::linkToCrud('✍️ Auteurs', 'fas fa-user-edit', Auteur::class);
        yield MenuItem::linkToCrud('🏷️ Catégories', 'fas fa-tags', Categorie::class);
        yield MenuItem::linkToCrud('🏢 Éditeurs', 'fas fa-building', Editeur::class);

        // Section utilisateurs
        yield MenuItem::section('👥 Gestion des Utilisateurs', 'fas fa-users');
        yield MenuItem::linkToCrud('👤 Utilisateurs', 'fas fa-users', User::class)
            ->setBadge($this->userRepository->count([]), 'success');
        yield MenuItem::linkToCrud('📥 Emprunts', 'fas fa-hand-holding', Emprunt::class)
            ->setBadge($this->empruntRepository->count(['statut' => 'emprunté']), 'warning');
        yield MenuItem::linkToCrud('💖 Wishlists', 'fas fa-heart', Wishlist::class);
        yield MenuItem::linkToCrud('⭐ Avis', 'fas fa-star', Avis::class);

        // Section communication
        yield MenuItem::section('📞 Communication', 'fas fa-envelope');
        yield MenuItem::linkToCrud('📧 Messages', 'fas fa-envelope', Contact::class)
            ->setBadge($this->contactRepository->count(['estLu' => false]), 'danger');

        // Section navigation
        yield MenuItem::section('🔗 Navigation', 'fas fa-compass');
        yield MenuItem::linkToUrl('🌐 Site public', 'fas fa-external-link-alt', '/')
            ->setLinkTarget('_blank');
    yield MenuItem::linkToLogout('🚪 Déconnexion', 'fa fa-sign-out');
}

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        // Cast $user to your User entity if needed
        /** @var \App\Entity\User $userEntity */
        $userEntity = $user;

        // Determine display name safely
        $name = $userEntity->getEmail();
        if (method_exists($userEntity, 'getNomComplet')) {
            $tmp = $userEntity->getNomComplet();
            if (!empty($tmp)) {
                $name = $tmp;
            }
        } elseif (method_exists($userEntity, 'getPrenom') && method_exists($userEntity, 'getNom')) {
            $tmp = trim($userEntity->getPrenom() . ' ' . $userEntity->getNom());
            if (!empty($tmp)) {
                $name = $tmp;
            }
        }

        // Determine avatar URL safely using is_callable to avoid static analysis issues
        $avatarUrl = '/images/default-avatar.svg';
        if (is_callable([$userEntity, 'getAvatarUrl'])) {
            $tmp = call_user_func([$userEntity, 'getAvatarUrl']);
            if (!empty($tmp)) {
                $avatarUrl = $tmp;
            }
        } elseif (is_callable([$userEntity, 'getAvatar'])) {
            $tmp = call_user_func([$userEntity, 'getAvatar']);
            if (!empty($tmp)) {
                $avatarUrl = $tmp;
            }
        }

        return parent::configureUserMenu($user)
            ->setName($name)
            ->setAvatarUrl($avatarUrl)
            ->displayUserAvatar(true)
            ->addMenuItems([
                MenuItem::linkToRoute('👤 Mon profil', 'fas fa-user', 'app_profile'),
                MenuItem::linkToRoute('⚙️ Paramètres', 'fas fa-cog', 'app_settings'),
                MenuItem::section(),
            ]);
    }

    private function getStats(): array
    {
        return [
            'total_livres' => $this->livreRepository->count([]),
            'total_utilisateurs' => $this->userRepository->count([]),
            'emprunts_actifs' => $this->empruntRepository->count(['statut' => 'emprunté']),
            'emprunts_en_retard' => $this->empruntRepository->countEmpruntsEnRetard(),
            'messages_non_lus' => $this->contactRepository->count(['estLu' => false]),
            'livres_populaires' => $this->livreRepository->findLivresPopulaires(5),
            'top_borrowed' => $this->empruntRepository->findTopBorrowedBooks(5),
            'top_wishlisted' => $this->wishlistRepository->findTopWishlistedBooks(5),
        ];
    }

    private function getRecentActivity(): array
    {
        return [
            'derniers_emprunts' => $this->empruntRepository->findBy([], ['dateEmprunt' => 'DESC'], 5),
            'derniers_messages' => $this->contactRepository->findBy([], ['dateEnvoi' => 'DESC'], 5),
            'derniers_avis' => $this->avisRepository->findBy([], ['dateCreation' => 'DESC'], 5),
        ];
    }
}