<?php

namespace App\Repository;

use App\Entity\Livre;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Livre>
 */
class LivreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Livre::class);
    }

    /**
     * Search livres by various criteria:
     * - 'q' => text matched against title or resume or isbn
     * - 'minPrice' => float
     * - 'maxPrice' => float
     * - 'author' => author name (partial match on prenom or nom)
     * - 'editor' => editor name (partial match)
     * - 'category' => category id or name
     *
     * @param array $criteria
     * @return Livre[]
     */
    public function search(array $criteria): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.auteurs', 'a')
            ->leftJoin('l.editeur', 'e')
            ->leftJoin('l.categorie', 'c')
            ->leftJoin('l.emprunts', 'em')
            ->leftJoin('l.avis', 'av')
            ->addSelect('a', 'e', 'c')
            ->groupBy('l.id');

        if (!empty($criteria['q'])) {
            $qb->andWhere('l.titre LIKE :q OR l.resume LIKE :q OR l.isbn LIKE :q')
               ->setParameter('q', '%'.$criteria['q'].'%');
        }

        if (isset($criteria['minPrice'])) {
            $qb->andWhere('l.prix >= :minPrice')
               ->setParameter('minPrice', $criteria['minPrice']);
        }

        if (isset($criteria['maxPrice'])) {
            $qb->andWhere('l.prix <= :maxPrice')
               ->setParameter('maxPrice', $criteria['maxPrice']);
        }

        if (!empty($criteria['author'])) {
            $qb->andWhere('a.nom LIKE :author OR a.prenom LIKE :author')
               ->setParameter('author', '%'.$criteria['author'].'%');
        }

        // filter by multiple author ids
        if (!empty($criteria['authors']) && is_array($criteria['authors'])) {
            $qb->andWhere('a.id IN (:authorIds)')
               ->setParameter('authorIds', $criteria['authors']);
        }

        if (!empty($criteria['editor'])) {
            $qb->andWhere('e.nom LIKE :editor')
               ->setParameter('editor', '%'.$criteria['editor'].'%');
        }

        // filter by multiple editor ids
        if (!empty($criteria['editors']) && is_array($criteria['editors'])) {
            $qb->andWhere('e.id IN (:editorIds)')
               ->setParameter('editorIds', $criteria['editors']);
        }

        if (!empty($criteria['category'])) {
            // allow category id or partial name
            if (is_numeric($criteria['category'])) {
                $qb->andWhere('c.id = :catId')
                   ->setParameter('catId', (int)$criteria['category']);
            } else {
                $qb->andWhere('c.designation LIKE :catName')
                   ->setParameter('catName', '%'.$criteria['category'].'%');
            }
        }

        // multiple categories (array of ids)
        if (!empty($criteria['categories']) && is_array($criteria['categories'])) {
            $qb->andWhere('c.id IN (:catIds)')
               ->setParameter('catIds', $criteria['categories']);
        }

        // availability filter: only livres with available copies
        // compute number of borrowed (statut = 'emprunté') and ensure qte > borrowedCount
        if (!empty($criteria['availability']) && $criteria['availability'] === 'available') {
            // add a hidden select that counts borrowed emprunts
            $qb->addSelect("SUM(CASE WHEN em.statut = 'emprunté' THEN 1 ELSE 0 END) AS HIDDEN borrowedCount");
            $qb->andHaving('l.qte > borrowedCount');
        }

        // rating filter: checkboxes like "i et plus" — interpret as minimal rating threshold.
        // If user selects multiple (e.g. 4 and 5), use the smallest selected value (>= 4).
        if (!empty($criteria['rating']) && is_array($criteria['rating'])) {
            $ratings = array_map('intval', $criteria['rating']);
            $minRating = (int) min($ratings);

            // consider only active reviews when computing average
            $qb->andWhere('av.isActive = 1');
            $qb->addSelect('AVG(av.note) AS HIDDEN avgNote');
            $qb->andHaving('avgNote >= :minRating');
            $qb->setParameter('minRating', $minRating);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Return most popular livres (by number of avis) limited by $limit
     * @return Livre[]
     */
    public function findLivresPopulaires(int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.avis', 'a')
            ->groupBy('l.id')
            ->orderBy('COUNT(a.id)', 'DESC')
            ->setMaxResults($limit);

        // return only Livre objects (Doctrine will hydrate entities)
        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Livre[] Returns an array of Livre objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('l.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Livre
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
