<?php
// src/Repository/WishlistRepository.php

namespace App\Repository;

use App\Entity\Wishlist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Wishlist>
 */
class WishlistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Wishlist::class);
    }

    /**
     * Return top wishlisted books with wishlist count.
     */
    public function findTopWishlistedBooks(int $limit = 5): array
    {
        $results = $this->createQueryBuilder('w')
            ->select('l.id, l.titre, COUNT(w.id) as count')
            ->join('w.livre', 'l')
            ->groupBy('l.id, l.titre')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Format the results properly
        $out = [];
        foreach ($results as $result) {
            $out[] = [
                'id' => $result['id'],
                'titre' => $result['titre'],
                'count' => (int)$result['count']
            ];
        }

        return $out;
    }
}