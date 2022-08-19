<?php

namespace App\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Psr\Cache\InvalidArgumentException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class BookController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des livres.
     *
     * @param BookRepository $bookRepository
     * @param SerializerInterface $serializer
     * @param Request $request
     * @param TagAwareCacheInterface $cache
     * @return JsonResponse
     * @throws InvalidArgumentException
     */
    #[Route('/api/books', name: 'books', methods: ['GET'])]
    public function getAllBooks(BookRepository         $bookRepository,
                                SerializerInterface    $serializer,
                                Request                $request,
                                TagAwareCacheInterface $cache
    ): JsonResponse
    {
        $page = $request->get('page', 1);
        $limit = $request->get('limit', 3);

        $idCache = "getAllBooks-" . $page . "-" . $limit;

        $jsonBookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            //echo ("L'ELEMENT N'EST PAS ENCORE EN CACHE !\n");
            $item->tag("booksCache");
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            $version =
            $context = SerializationContext::create()->setGroups(["getBooks"]);
            $context->setVersion("2.0");
            return $serializer->serialize($bookList, 'json', $context);
        });

        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    // sans paramConverter
    /*#[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(int $id, SerializerInterface $serializer, BookRepository $bookRepository): JsonResponse {

        $book = $bookRepository->find($id);
        if ($book) {
            $jsonBook = $serializer->serialize($book, 'json');
            return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
        }
        return new JsonResponse(null, Response::HTTP_NOT_FOUND);
    }*/

    // avec
    #[Route('/api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book                $book,
                                  SerializerInterface $serializer,
                                  VersioningService   $versioningService,
    ): JsonResponse
    {
        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $context->setVersion($version);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        return new JsonResponse($jsonBook, Response::HTTP_OK, ['accept' => 'json'], true);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un livre')]
    public function delete(Book                   $book,
                           EntityManagerInterface $entityManager,
                           TagAwareCacheInterface $cache
    ): JsonResponse
    {
        //On vide le cache avant la supression
        $cache->invalidateTags(["booksCache"]);
        $entityManager->remove($book);
        $entityManager->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    public function create(Request                $request,
                           SerializerInterface    $serializer,
                           EntityManagerInterface $entityManager,
                           UrlGeneratorInterface  $urlGenerator,
                           AuthorRepository       $authorRepository,
                           ValidatorInterface     $validator,
                           TagAwareCacheInterface $cache
    ): JsonResponse
    {

        $book = new Book();
        // Récupération de l'ensemble des données envoyées sous forme de tableau
        $content = $request->toArray();
        $bookTitle = $content['title'];
        $coverText = $content['coverText'];

        $book->setTitle($bookTitle);
        $book->setCoverText($coverText);

        // On vérifie les erreurs
        $errors = $validator->validate($book);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }


        if (isset($content['firstNameAuthor']) && isset($content['lastNameAuthor'])) {
            $author = new Author();

            $author->setFirstName($content['firstNameAuthor']);
            $author->setLastName($content['lastNameAuthor']);

            //On vide le cache avant l'ajout
            $cache->invalidateTags(["booksCache"]);
            $entityManager->persist($author);
            $entityManager->flush();

            $book->setAuthor($authorRepository->find($authorRepository->find($author->getId())));
        }

        $entityManager->persist($book);
        $entityManager->flush();

        $context = SerializationContext::create()->setGroups('getBooks');
        $jsonBook = $serializer->serialize($book, 'json', $context);

        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    /**
     * @throws InvalidArgumentException
     */
    #[Route('/api/books/{id}', name: "updateBook", methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour éditer un livre')]
    public function updateBook(Request                $request,
                               SerializerInterface    $serializer,
                               Book                   $currentBook,
                               EntityManagerInterface $entityManager, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());

        // On vérifie les erreurs
        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        $content = $request->toArray();
        if (isset($content['firstNameAuthor']) && isset($content['lastNameAuthor'])) {
            $author = new Author();

            $author->setFirstName($content['firstNameAuthor']);
            $author->setLastName($content['lastNameAuthor']);

            //On vide le cache avant l'ajout
            $cache->invalidateTags(["booksCache"]);
            $entityManager->persist($author);
            $entityManager->flush();

            $currentBook->setAuthor($authorRepository->find($authorRepository->find($author->getId())));
        }

        $entityManager->persist($currentBook);
        $entityManager->flush();

        // On vide le cache.
        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
