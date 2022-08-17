<?php

namespace App\Controller;

use App\Entity\Author;
use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class BookController extends AbstractController
{
    #[Route('/api/books', name: 'books', methods: ['GET'])]
    public function getBookList(BookRepository $bookRepository, SerializerInterface $serializer): JsonResponse
    {
        $bookList = $bookRepository->findAll();
        $jsonBookList = $serializer->serialize($bookList, 'json', ['groups' => 'getBooks']);
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
    public function getDetailBook(Book $book, SerializerInterface $serializer): JsonResponse
    {
        $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']);
        return new JsonResponse($jsonBook, Response::HTTP_OK, ['accept' => 'json'], true);
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    public function delete(Book $book, EntityManagerInterface $entityManager): JsonResponse
    {
        $entityManager->remove($book);
        $entityManager->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    public function create(Request                $request,
                           SerializerInterface    $serializer,
                           EntityManagerInterface $entityManager,
                           UrlGeneratorInterface  $urlGenerator,
                           AuthorRepository       $authorRepository,
                           ValidatorInterface     $validator
    ): JsonResponse
    {

        // Récupération de l'ensemble des données envoyées sous forme de tableau
        $content = $request->toArray();

        $bookTitle = $content['title'] ?? null;
        $coverText = $content['coverText'] ?? null;

        $author = new Author();
        $book = new Book();
        // On vérifie les erreurs
        $errors = $validator->validate([$book, $author]);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

            $book->setTitle($bookTitle) ?? null;
            $book->setCoverText($coverText) ?? null;

        $firstNameAuthor = $content['firstNameAuthor'] ?? null;
        $lastNameAuthor = $content['lastNameAuthor'] ?? null;

        $author->setFirstName($firstNameAuthor);
        $author->setLastName($lastNameAuthor);

        $entityManager->persist($author);
        $entityManager->flush();

        $book->setAuthor($authorRepository->find($authorRepository->find($author->getId())));


        $entityManager->persist($book);
        $entityManager->flush();

        $jsonBook = $serializer->serialize($book, 'json', ['groups' => 'getBooks']);

        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['PUT'])]
    public function update(Request                $request,
                           SerializerInterface    $serializer,
                           Book                   $currentBook,
                           EntityManagerInterface $entityManager,
                           AuthorRepository       $authorRepository
    ): JsonResponse
    {
        $updatedBook = $serializer->deserialize($request->getContent(),
            Book::class, 'json',
            [AbstractNormalizer::OBJECT_TO_POPULATE => $currentBook]);


        $idAuthor = $currentBook->getAuthor() ?? -1;
        $updatedBook->setAuthor($authorRepository->find($idAuthor));

        $entityManager->persist($currentBook);
        $entityManager->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    // On vérifie les erreurs
    public function getErrors($entity)
    {

    }
}
