<?php

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    // ✅ NOUVELLES METHODES
    public function findMessagesToUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.destinataire = :user')
            ->andWhere('c.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', 'admin_to_user')
            ->orderBy('c.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findMessagesFromUsers(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.type = :type')
            ->setParameter('type', 'user_to_admin')
            ->orderBy('c.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countUnreadMessagesForUser(User $user): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.destinataire = :user')
            ->andWhere('c.estLu = false')
            ->andWhere('c.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', 'admin_to_user')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadFromUsers(): int
    {
        return $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.estLu = false')
            ->andWhere('c.type = :type')
            ->setParameter('type', 'user_to_admin')
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return Contact[] Returns an array of Contact objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Contact
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}