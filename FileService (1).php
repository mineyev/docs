<?php

namespace Iqoption\v1\Service;

use Doctrine\DBAL\Connection;
use Iqoption\v1\Model\FileAlias;
use Iqoption\v1\Model\FileAliasInterface;
use Iqoption\v1\Model\FileMetadata;
use Iqoption\v1\Storage\FilePath;
use Iqoption\v1\Storage\FileStorageAdapter;
use Iqoption\v1\Storage\FileStorageAdapterInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Class FileService
 * @package Iqoption\v1\Service
 */
class FileService
{
    /** @var Connection */
    private $connection;

    /** @var FileMetadataManagerDbal */
    private $fileMetadataManagerDbal;

    /** @var FileAliasManagerDbal */
    private $fileAliasManagerDbal;

    /** @var FileStorageAdapterInterface */
    private $fileStorageAdapter;

    /** @var AdapterHelper */
    private $adapterHelper;

    /**
     * FileService constructor.
     * @param Connection $connection
     * @param FileAliasManagerDbal $fileAliasManagerDbal
     * @param FileMetadataManagerDbal $fileMetadataManagerDbal
     * @param AdapterHelper $adapterHelper
     */
    public function __construct(
        Connection $connection,
        FileAliasManagerDbal $fileAliasManagerDbal,
        FileMetadataManagerDbal $fileMetadataManagerDbal,
        AdapterHelper $adapterHelper
    )
    {
        $this->connection = $connection;
        $this->fileAliasManagerDbal = $fileAliasManagerDbal;
        $this->fileMetadataManagerDbal = $fileMetadataManagerDbal;
        $this->adapterHelper = $adapterHelper;
    }

    /**
     * @param FileAlias $fileAlias
     * @param $fileBody
     * @param null $origFileName
     * @param null $access
     * @param null $expire
     * @return FilePath
     */
    public function replaceFile(FileAlias $fileAlias, $fileBody, $origFileName = null, $access = null, $expire = null)
    {
        $fileAlias
            ->setOriginalName($origFileName)
            ->setAccess($access)
            ->setExpire($expire ? $expire : null);

        $replaceFileExists = $this->fileMetadataManagerDbal->getFileByMd5Hash(md5($fileBody));
        if ($replaceFileExists) {
            $fileAlias->setFileUri($replaceFileExists->getFileUri());
            $this->fileAliasManagerDbal->updateByAlias($fileAlias, $fileAlias->getAlias());
            return $this->createFilePath($fileAlias);
        }

        $fileAliases = $this
            ->fileAliasManagerDbal
            ->findAllFileAliasesByFileUri($fileAlias->getFileUri());

        if (!$fileAliases) {
            throw new NotFoundHttpException('Resource not exists');
        }

        $fileMetadata = $this
            ->fileMetadataManagerDbal
            ->factoryFileMetadata()
            ->setMd5Hash(md5($fileBody));

        if (count($fileAliases) === 1) {
            //update content file
            $this->fileStorageAdapter->replaceFileFromBlob($fileAlias->getFileUriWithoutStorage(), $fileBody);
            //update md5 hash
            $this->fileMetadataManagerDbal->updateFileMetadata($fileMetadata, $fileAlias->getFileUri());
            //update: access, expire, orig_name
            $this->fileAliasManagerDbal->updateByAlias($fileAlias, $fileAlias->getAlias());
        } else {

            $filePath = $this->fileStorageAdapter->createFileFromBlob($fileBody, pathinfo($fileAlias->getOriginalName(), PATHINFO_EXTENSION));

            $fileMetadata->setFileUri($filePath->getFullUri());
            $fileAlias->setFileUri($fileMetadata->getFileUri());

            $this
                ->fileMetadataManagerDbal
                ->insertFileMetadata($fileMetadata);
            $this
                ->fileAliasManagerDbal
                ->updateByAlias($fileAlias, $fileAlias->getAlias());
        }

        return isset($filePath) ? $filePath : $this->createFilePath($fileAlias);
    }

    /**
     * @param $alias
     */
    public function delete($alias)
    {
        $fileAlias = $this->fileAliasManagerDbal->findByAlias($alias);
        if (!$fileAlias) {
            throw new NotFoundHttpException('Resource not exists');
        }

        $fileAliases = $this->fileAliasManagerDbal->findAllFileAliasesByFileUri($fileAlias->getFileUri());
        if (!$fileAliases) {
            throw new NotFoundHttpException('Resource not exists');
        }

        if (count($fileAliases) === 1) {
            /** @var FileStorageAdapterInterface $fileAdapter */
            $fileAdapter = $this->adapterHelper->getFileAdapterByFileUri($fileAlias->getFileUri());
            $fileUriUnmounted = $this->adapterHelper->getFileUriUnmounted($fileAlias->getFileUri());

            $fileAdapter->deleteFile($fileUriUnmounted);
            $this->fileMetadataManagerDbal->deleteFileMetadata($fileAlias->getFileUri());
            $this->fileAliasManagerDbal->deleteByAlias($fileAlias->getAlias());
        } else {
            $this->fileAliasManagerDbal->deleteByAlias($fileAlias->getAlias());
        }
    }

    /**
     * @param FileAliasInterface $fileAlias
     * @param $filePath
     * @return FilePath
     */
    public function saveFileByPath(FileAliasInterface $fileAlias, $filePath)
    {
        if (!$this->fileStorageAdapter) {
            throw new \Exception('File storage adapter must be specified');
        }

        $fileExists = $this->fileMetadataManagerDbal->getFileByMd5Hash(md5_file($filePath));
        $fileExt    = pathinfo($fileAlias->getOriginalName(), PATHINFO_EXTENSION);

        if (!$fileExists) {
            $filePath = $this
                ->fileStorageAdapter
                ->createFileFromPath($filePath, $fileExt);

            $fileMetadata = $this->fileMetadataManagerDbal
                ->factoryFileMetadata()
                ->setFileUri($filePath->getFullUri())
                ->setMd5Hash(md5_file($filePath->getAbsolutePath()));

            $fileAlias->setFileUri($filePath->getFullUri());
            $this->saveUniqueFile($fileMetadata, $fileAlias);
        } else {
            $fileAlias->setFileUri($fileExists->getFileUri());
            $this->fileAliasManagerDbal->insertFileAlias($fileAlias);
            $filePath = $this->createFilePath($fileAlias);
        }

        return $filePath;
    }

    /**
     * @param FileMetadata $fileMetadata
     * @param FileAlias $fileAlias
     * @throws \Exception
     */
    public function saveUniqueFile(FileMetadata $fileMetadata, FileAlias $fileAlias)
    {
        $this->connection->beginTransaction();
        try {
            $this->fileMetadataManagerDbal->insertFileMetadata($fileMetadata);
            $this->fileAliasManagerDbal->insertFileAlias($fileAlias);
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * @param string $originName
     * @return string
     */
    public function generateAlias($originName)
    {
        $fileExt = pathinfo($originName, PATHINFO_EXTENSION);

        return $this->fileStorageAdapter->getFilePathGenerator()->generateFilePath($fileExt)->getFullUri();
    }


    /**
     * @param string $fileBody
     * @param string $origFileName
     * @param string $access
     * @param string $expire
     * @param null $alias
     * @return FileAlias
     * @throws \Exception
     */
    public function saveFileFromBlob($fileBody, $origFileName = null, $access = null, $expire = null, $alias = null)
    {
        if (!$this->fileStorageAdapter) {
            throw new \Exception('File path generator must be specified');
        }

        $alias = $alias ? $alias : $this->generateAlias($origFileName);
        $fileAlias = $this->fileAliasManagerDbal
            ->factoryFileAlias()
            ->setAlias($alias)
            ->setOriginalName($origFileName)
            ->setAccess($access)
            ->setExpire($expire ? $expire : null);

        $fileMetadata = $this->fileMetadataManagerDbal->getFileByMd5Hash(md5($fileBody));
        if (!$fileMetadata) {
            $fileExt = pathinfo($fileAlias->getOriginalName(), PATHINFO_EXTENSION);
            $filePath = $this->fileStorageAdapter->createFileFromBlob($fileBody, $fileExt);

            $fileMetadata = $this
                ->fileMetadataManagerDbal
                ->factoryFileMetadata()
                ->setMd5Hash(md5($fileBody))
                ->setFileUri($filePath->getFullUri());

            $fileAlias->setFileUri($filePath->getFullUri());

            $this->saveUniqueFile($fileMetadata, $fileAlias);
        } else {
            $fileAlias->setFileUri($fileMetadata->getFileUri());
            $this->fileAliasManagerDbal->insertFileAlias($fileAlias);
        }

        return $fileAlias;
    }

    /**
     * @param FileAlias $fileAlias
     * @return FilePath
     */
    public function createFilePath(FileAlias $fileAlias)
    {
        return new FilePath(
            $this->adapterHelper->getFileUriUnmounted($fileAlias->getFileUri()),
            $this->fileStorageAdapter->getFilePathGenerator()->getDestinationDir(),
            $this->fileStorageAdapter->getFilePathGenerator()->getBaseUri()
        );
    }

    /**
     * @param FileStorageAdapterInterface $fileStorageAdapter
     * @return FileService
     */
    public function setFileStorageAdapter(FileStorageAdapterInterface $fileStorageAdapter)
    {
        $this->fileStorageAdapter = $fileStorageAdapter;

        return $this;
    }

    /**
     * @return FileAliasManagerDbal
     */
    public function getFileAliasManagerDbal()
    {
        return $this->fileAliasManagerDbal;
    }

    /**
     * @return AdapterHelper
     */
    public function getAdapterHelper()
    {
        return $this->adapterHelper;
    }
}