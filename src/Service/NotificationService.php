<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Entity\Contact;
use App\Entity\Livre;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class NotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepository,
        private ?MailerInterface $mailer = null,
        private string $fromAddress = 'noreply@biblio.local',
        private string $appName = 'Biblio-Symfony'
    ) {
    }

    public function createForUser(User $user, string $title, string $message, string $type = 'info', array $data = [], bool $sendEmail = false): Notification
    {
        $notification = new Notification();
        $notification->setUser($user)
            ->setTitle($title)
            ->setMessage($message)
            ->setType($type)
            ->setData($data);

        $this->em->persist($notification);
        $this->em->flush();

        // ✅ CORRECTION : Envoi d'email amélioré
        if ($sendEmail && $this->mailer && $user->getEmail()) {
            try {
                $email = (new TemplatedEmail())
                    ->from(new Address($this->fromAddress, $this->appName))
                    ->to($user->getEmail())
                    ->subject('🔔 ' . $title)
                    ->htmlTemplate('emails/notification_simple.html.twig')
                    ->context([
                        'title' => $title, 
                        'message' => $message, 
                        'user' => $user,
                        'data' => $data
                    ]);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                // Logger l'erreur si vous avez un système de logs
                error_log('Erreur envoi email notification: ' . $e->getMessage());
            }
        }

        return $notification;
    }

    // ✅ NOUVELLE MÉTHODE : Notifier pour un nouveau message
    public function notifyNewContactMessage(Contact $contact, User $adminUser): void
    {
        $title = '📨 Nouveau message de contact';
        $message = "Nouveau message de {$contact->getNomComplet()} : {$contact->getSujet()}";
        
        $this->createForUser(
            $adminUser,
            $title,
            $message,
            'warning',
            [
                'contactId' => $contact->getId(),
                'actionUrl' => $this->generateUrl('app_contact_edit', ['id' => $contact->getId()])
            ],
            true // Envoyer un email
        );
    }

    // ✅ NOUVELLE MÉTHODE : Notifier la réponse à un message
    public function notifyContactResponse(Contact $contact, User $user): void
    {
        $title = '📩 Réponse à votre message';
        $message = "L'administrateur a répondu à votre message : {$contact->getSujet()}";
        
        $this->createForUser(
            $user,
            $title,
            $message,
            'info',
            [
                'contactId' => $contact->getId(),
                'actionUrl' => $this->generateUrl('app_contact_my')
            ],
            false // Ne pas envoyer d'email car déjà fait séparément
        );
    }

    public function createForAllUsers(UserRepository $userRepo, string $title, string $message, string $type = 'info', array $data = [], bool $sendEmail = false): int
    {
        $users = $userRepo->findAll();
        $count = 0;
        foreach ($users as $user) {
            $this->createForUser($user, $title, $message, $type, $data, $sendEmail);
            $count++;
        }

        return $count;
    }

    // ✅ CORRECTION : Méthode pour générer les URLs
    private function generateUrl(string $route, array $parameters = [], int $referenceType = \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL): string
    {
        // Cette méthode devrait être injectée depuis le container
        // Pour l'instant, on retourne une URL relative
        return $route . ($parameters ? '?' . http_build_query($parameters) : '');
    }

    // Dans NotificationService.php - Ajoutez cette méthode
/**
 * Notifie tous les utilisateurs lorsqu'un nouveau livre est ajouté
 */
public function notifyNewBook(Livre $livre, UserRepository $userRepository): void
{
    $title = '📚 Nouveau livre disponible !';
    $message = 'Le livre "' . $livre->getTitre() . '" a été ajouté à la bibliothèque.';
    
    $this->createForAllUsers(
        $userRepository,
        $title,
        $message,
        'success',
        [
            'livreId' => $livre->getId(),
            'actionUrl' => '/livre/' . $livre->getId() // URL relative
        ],
        false // Ne pas envoyer d'email
    );
}
}