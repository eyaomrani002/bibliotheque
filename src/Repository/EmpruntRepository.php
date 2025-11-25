<?php

namespace App\Repository;

use App\Entity\Emprunt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Emprunt>
 */
class EmpruntRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emprunt::class);
    }

    /**
     * Count emprunts en retard (statut 'emprunté' et dateRetourPrevue passée)
     */
    public function countEmpruntsEnRetard(): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.statut = :statut')
            ->andWhere('e.dateRetourPrevue < :now')
            ->setParameter('statut', 'emprunté')
            ->setParameter('now', new \DateTime())
        ;

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Return top borrowed books with borrow count.
     * Returns array of ['livre' => Livre, 'count' => int]
     *
     * @return array
     */
    public function findTopBorrowedBooks(int $limit = 5): array
    {
        // Build query from Livre as root to avoid selecting entities through non-root alias
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('l.id AS id, l.titre AS titre, COUNT(e.id) AS borrowCount')
            ->from(\App\Entity\Livre::class, 'l')
            ->leftJoin('l.emprunts', 'e')
            ->groupBy('l.id')
            ->orderBy('borrowCount', 'DESC')
            ->setMaxResults($limit);

        $results = $qb->getQuery()->getArrayResult();

        // Normalize keys to 'id','titre','count'
        $out = [];
        foreach ($results as $r) {
            $out[] = ['id' => (int)$r['id'], 'titre' => $r['titre'], 'count' => (int)$r['borrowCount']];
        }

        return $out;
    }

    //    /**
    //     * @return Emprunt[] Returns an array of Emprunt objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('e.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Emprunt
    //    {
    //        return $this->createQueryBuilder('e')
    //            ->andWhere('e.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
