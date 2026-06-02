<?php

declare(strict_types=1);

namespace App\Controller;

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Tenancy\Bundle\Context\TenantContext;

/**
 * Deliberately authn-free for the demo. Remove from any non-local deployment.
 *
 * Exercises the bundle's FilesystemPrefixingDecorator (prefix mode) end-to-end:
 * $usersStorage is the bundle-decorated FilesystemOperator wired by FilesystemContractPass.
 * Controller never touches the tenant slug — the decorator prepends tenant_{slug}/ transparently.
 *
 * @see docs/user-guide/filesystem-bootstrapper.md for the trust boundary / path-traversal note.
 */
class TenantUploadController extends AbstractController
{
    public function __construct(
        private readonly FilesystemOperator $usersStorage,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/uploads', name: 'tenant_upload_index', methods: ['GET'])]
    public function index(): Response
    {
        $files = [];
        foreach ($this->usersStorage->listContents('') as $item) {
            $files[] = $item->path();
        }

        return $this->render('upload/index.html.twig', [
            'tenant' => $this->tenantContext->getTenant(),
            'files' => $files,
        ]);
    }

    #[Route('/uploads', name: 'tenant_upload_create', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $uploadedFile = $request->files->get('upload');

        if (!$uploadedFile instanceof UploadedFile) {
            return $this->redirectToRoute('tenant_upload_index');
        }

        // basename() sanitises user-supplied names against path traversal at the application layer.
        // The bundle treats path args as TRUSTED — see docs/user-guide/filesystem-bootstrapper.md §Trust boundary.
        $filename = basename($uploadedFile->getClientOriginalName());

        $resource = fopen($uploadedFile->getRealPath(), 'r');
        if (false !== $resource) {
            $this->usersStorage->writeStream($filename, $resource);
            fclose($resource);
        }

        return $this->redirectToRoute('tenant_upload_index');
    }
}
