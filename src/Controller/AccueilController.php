<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\LivreRepository;
use App\Repository\AuteurRepository;
use App\Repository\CategorieRepository;
use App\Repository\EditeurRepository;
use App\Repository\EmpruntRepository;
use App\Repository\AvisRepository;
use App\Repository\WishlistRepository;
use App\Form\ContactPublicType;

final class AccueilController extends AbstractController
{
    #[Route('/', name: 'app_accueil')]
    public function index(
        Request $request,
        LivreRepository $livreRepo,
        AuteurRepository $auteurRepo,
        CategorieRepository $categorieRepo,
        EditeurRepository $editeurRepo,
        EmpruntRepository $empruntRepo,
        AvisRepository $avisRepo,
        WishlistRepository $wishlistRepo
    ): Response {
        $search = $request->query->get('q');
        $minPrice = $request->query->get('minPrice');
        $maxPrice = $request->query->get('maxPrice');
        $author = $request->query->get('author');
        $editor = $request->query->get('editor');
        // categories[] may be multiple selected checkboxes
        // InputBag::get() throws when the stored value is non-scalar (array). Read the raw query array instead.
        $queryAll = $request->query->all();
        $categoriesSelected = $queryAll['categories'] ?? null;
        $availability = $queryAll['availability'] ?? null;
        $ratings = $queryAll['rating'] ?? null;
        // authors[] and editors[] multi-selects
        $authorsSelected = $queryAll['authors'] ?? null;
        $editorsSelected = $queryAll['editors'] ?? null;
        $user = $this->getUser();
        
        // Récupérer les livres en appliquant les filtres de recherche si fournis
        $criteria = [];
        if ($search) $criteria['q'] = $search;
        if ($minPrice !== null && $minPrice !== '') $criteria['minPrice'] = (float)$minPrice;
        if ($maxPrice !== null && $maxPrice !== '') $criteria['maxPrice'] = (float)$maxPrice;
        if ($author) $criteria['author'] = $author;
        if ($editor) $criteria['editor'] = $editor;
        // single category param fallback
        if ($request->query->get('category')) {
            $criteria['category'] = $request->query->get('category');
        }
        // multiple categories
        if (!empty($categoriesSelected)) {
            // sanitize to integers when possible
            $cats = array_filter(array_map(function($v){ return is_numeric($v) ? (int)$v : $v; }, (array)$categoriesSelected));
            $criteria['categories'] = $cats;
        }
        // multiple authors
        $selectedAuthors = [];
        if (!empty($authorsSelected)) {
            $authors = array_filter(array_map(function($v){ return is_numeric($v) ? (int)$v : $v; }, (array)$authorsSelected));
            if (!empty($authors)) {
                $criteria['authors'] = $authors;
                $selectedAuthors = $authors;
            }
        }

        // multiple editors
        $selectedEditors = [];
        if (!empty($editorsSelected)) {
            $eds = array_filter(array_map(function($v){ return is_numeric($v) ? (int)$v : $v; }, (array)$editorsSelected));
            if (!empty($eds)) {
                $criteria['editors'] = $eds;
                $selectedEditors = $eds;
            }
        }
        if (!empty($availability)) {
            $criteria['availability'] = $availability;
        }
        if (!empty($ratings)) {
            $criteria['rating'] = (array)$ratings;
        }

        // prepare selected values for template so inputs remain checked after submit
        $selectedCategories = !empty($categoriesSelected) ? array_map(function($v){ return is_numeric($v) ? (int)$v : $v; }, (array)$categoriesSelected) : [];
        $selectedRatings = !empty($ratings) ? array_map('intval', (array)$ratings) : [];
        $selectedAvailability = $availability ?? 'all';
        $selectedMinPrice = $minPrice !== null ? $minPrice : '';
        $selectedMaxPrice = $maxPrice !== null ? $maxPrice : '';

        $livres = !empty($criteria) ? $livreRepo->search($criteria) : $livreRepo->findAll();
        $auteurs = $auteurRepo->findAll();
        $categories = $categorieRepo->findAll();
        $editeurs = $editeurRepo->findAll();

        // Récupérer les données utilisateur si connecté
        $userEmprunts = $user ? $empruntRepo->findBy(['user' => $user, 'statut' => 'emprunté']) : [];
        $userWishlist = $user ? $wishlistRepo->findBy(['user' => $user]) : [];
        $userAvis = $user ? $avisRepo->findBy(['user' => $user]) : [];

        // Statistiques globales
        $stats = [
            'livres' => $livreRepo->count([]),
            'auteurs' => $auteurRepo->count([]),
            'categories' => $categorieRepo->count([]),
            'editeurs' => $editeurRepo->count([]),
            'empruntsActifs' => $empruntRepo->count(['statut' => 'emprunté']),
            'avis' => $avisRepo->count([]),
        ];

        return $this->render('accueil/index.html.twig', [
            'livres' => $livres,
            'auteurs' => $auteurs,
            'categories' => $categories,
            'editeurs' => $editeurs,
            'search' => $search,
            'selectedAuthors' => $selectedAuthors,
            'selectedEditors' => $selectedEditors,
            'selectedCategories' => $selectedCategories,
            'selectedRatings' => $selectedRatings,
            'selectedAvailability' => $selectedAvailability,
            'selectedMinPrice' => $selectedMinPrice,
            'selectedMaxPrice' => $selectedMaxPrice,
            'userEmprunts' => $userEmprunts,
            'userWishlist' => $userWishlist,
            'userAvis' => $userAvis,
            'stats' => $stats,
            // public contact form
            'contactForm' => $this->createForm(ContactPublicType::class)->createView(),
        ]);
    }
}