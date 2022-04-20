<?php

namespace Antipodes\Wordpress\SwitchComments;

use Dotenv\Dotenv;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SwitchCommentsCommand extends Command
{

    /**
     * Name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'wp:switch-comments';

    /**
     * Description of the command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Create an SQL Query to switch comments from a Wordpress database to another Wordpress database mapping the post IDs.';

    /**
     * Last ID generated for the SQL dump.
     *
     * @var int
     */
    private static int $lastIdGenerated = 0;

    /**
     * Is the SQL dump file been created ?
     *
     * @var bool
     */
    private static bool $sqlDumpCreated = false;

    private $sqlDumpFile = null;

    /**
     * Posts Ids map.
     *
     * [
     *     'First Wordpress Post ID' => 'Second Wordpress Post ID',
     * ]
     *
     * @var array
     */
    private array $postIds;

    /**
     * Database instance.
     *
     * @var Database
     */
    private Database $db;

    /**
     * Authors Ids map.
     *
     * [
     *     'First Wordpress Author ID' => 'Second Wordpress Author ID',
     * ]
     *
     * @var array
     */
    private array $authors;

    /**
     * @throws FileNotFoundException
     */
    public function __construct()
    {
        parent::__construct();

        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();

        $this->postIds = $this->getPostIds();
        $this->authors = $this->getAuthorIds();
        $this->db = $this->getDatabase(
            $_ENV['DB_NAME'],
            $_ENV['DB_USER'],
            $_ENV['DB_PASSWORD'],
            $_ENV['DB_HOST'],
            $_ENV['DB_WP_PREFIX']
        );
    }

    protected function configure(): void
    {
        $this->addArgument(
            'last-comment-id',
            InputArgument::REQUIRED,
            'The last inserted comment ID in `wp_comments` table on your new Wordpress website.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        //@formatter:off
        // Récupérer tous les commentaires dans la table 'wp_comments' qui ont le 'comment_post_ID' qui est équivalent à un des ID clé du tableau postIds sans les réponses
        // Créer un tableau avec les commentaires en haut et les réponses en bas etc.. etc..
        // Récupérer les metadatas de chaque commentaire
        // Ajouter une metadata pour dire en gros que c'est ce programme qui les a ajouté dans la base de données
        // BOUCLER SUR TOUT LE TABLEAU POUR :
            // - Changer les ID de 'comment_post_ID' pour que ça colle avec les ID spécifiés dans la valeur du tableau postIds
            // - Changer les ID de 'user_id' pour coller au tableau authors
            // - Changer 'comment_author', 'comment_author_email' pour coller à l'user courant (de authors)
        // FIN BOUCLE
        // BOUCLER SUR TOUT LE TABLEAU POUR :
            // - Récupérer le dernier ID 'comment_ID' de 'wp_comments'
            // - Supprimer 'comment_ID' et le changer par dernier ID + 1
            // - Boucler sur les enfants pour changer 'comment_ID' par l'ID plus haut (donc ID + 1) et changer 'comment_ID' par ID + 2 etc .. etc.. (Pareil pour 'comment_parent')
        // FIN BOUCLE
        // Créer le dump SQL
        //@formatter:on

        $comments = $this->db->getComments($this->postIds);
        foreach ($this->postIds as $oldPostId => $newPostId) {
            $comments = $this->replaceOldValue('comment_post_ID', "{$oldPostId}", "{$newPostId}", $comments);
        }
        foreach ($this->authors as $oldAuthorId => $newAuthor) {
            $comments = $this->replaceOldValue('user_id', "{$oldAuthorId}", "{$newAuthor['id']}", $comments);
            $comments = $this->replaceAuthor($newAuthor, $comments);
        }

        $this->generateSqlDump($this->prepareSqlDump($comments, (int) $input->getArgument('last-comment-id')));

        return Command::SUCCESS;
    }

    /**
     * Replace author field keys in the haystack.
     *
     * @param array $author
     * @param array $haystack
     *
     * @return array
     */
    private function replaceAuthor(array $author, array $haystack): array
    {
        foreach ($haystack as $key => $value) {
            if (isset($value['user_id']) && $value['user_id'] == $author['id']) {
                $haystack[$key]['comment_author'] = $author['name'];
                $haystack[$key]['comment_author_email'] = $author['email'];
            }
            if (isset($value['WP_SWITCH_COMMENTS']['answers'])) {
                $haystack[$key]['WP_SWITCH_COMMENTS']['answers'] = $this->replaceAuthor($author, $value['WP_SWITCH_COMMENTS']['answers']);
            }
        }
        return $haystack;
    }

    /**
     * Replace old value of a key in deep array with new value.
     *
     * @param string $key      Key of the array to search.
     * @param string $oldValue Old value of the key.
     * @param string $newValue New value for the key.
     * @param array  $haystack The array to use.
     *
     * @return array The array modified.
     */
    private function replaceOldValue(string $key, string $oldValue, string $newValue, array $haystack): array
    {
        array_walk_recursive(
            $haystack,
            function (&$v, $k) use ($key, $oldValue, $newValue) {
                if ($k === $key && $v === $oldValue) {
                    $v = $newValue;
                }
            }
        );
        return $haystack;
    }

    /**
     * Get a database instance with the dump of the Wordpress site.
     *
     * @param string $dbName
     * @param string $dbUser
     * @param string $dbPassword
     * @param string $dbHost
     * @param string $dbWpPrefix
     *
     * @return Database
     * @throws FileNotFoundException
     */
    private function getDatabase(string $dbName, string $dbUser, string $dbPassword, string $dbHost, string $dbWpPrefix): Database
    {
        $filepath = dirname(__DIR__) . '/config/database.sql';
        if (!file_exists($filepath)) {
            throw new FileNotFoundException("The file 'config/database.sql' does not exist.");
        }

        return new Database(
            file_get_contents($filepath),
            $dbWpPrefix,
            "mysql:dbname={$dbName};host={$dbHost}",
            $dbUser,
            $dbPassword,
            [
                PDO::ATTR_ERRMODE,
            ],
        );
    }

    /**
     * Retrieve the post ids mapping from the 'config/post-ids.php' file.
     *
     * @return array
     * @throws FileNotFoundException
     */
    private function getPostIds(): array
    {
        $filepath = dirname(__DIR__) . '/config/post-ids.php';
        if (!file_exists($filepath)) {
            throw new FileNotFoundException("The file 'config/post-ids.php' does not exist.");
        }

        return require_once($filepath);
    }

    /**
     * Retrieve the author ids mapping from the 'config/author-ids.php' file.
     *
     * @return array
     * @throws FileNotFoundException
     */
    private function getAuthorIds(): array
    {
        $filepath = dirname(__DIR__) . '/config/authors.php';
        if (!file_exists($filepath)) {
            throw new FileNotFoundException("The file 'config/authors.php' does not exist.");
        }

        return require_once($filepath);
    }

    /**
     * Generate the SQL dump file to insert comments, answers and metadatas.
     *
     * @param array    $comments
     * @param int|null $lastInsertedId
     *
     * @return array
     */
    private function prepareSqlDump(array $comments, ?int $lastInsertedId = null): array
    {
        foreach ($comments as &$comment) {
            if (self::$lastIdGenerated === 0 && $lastInsertedId) {
                self::$lastIdGenerated = $lastInsertedId;
            }
            $comment['comment_ID'] = (string) self::$lastIdGenerated;
            if (isset($comment['WP_SWITCH_COMMENTS']['metadatas'])) {
                foreach ($comment['WP_SWITCH_COMMENTS']['metadatas'] as &$metadata) {
                    $metadata['comment_id'] = (string) self::$lastIdGenerated;
                }
            }
            if (isset($comment['comment_parent']) && $comment['comment_parent'] !== '0') {
                $comment['comment_parent'] = (string) (self::$lastIdGenerated - 1);
            }
            if (isset($comment['WP_SWITCH_COMMENTS']['answers'])) {
                self::$lastIdGenerated++;
                $comment['WP_SWITCH_COMMENTS']['answers'] = $this->prepareSqlDump($comment['WP_SWITCH_COMMENTS']['answers']);
            }
            self::$lastIdGenerated++;
        }
        return $comments;
    }

    /**
     * Generate SQL Dump file.
     *
     * @param array  $comments
     * @param string $table Table to insert in.
     */
    private function generateSqlDump(array $comments, string $table = 'wp_comments'): void
    {
        if (!self::$sqlDumpCreated && file_exists('dump.sql')) {
            unlink('dump.sql');
        }
        $this->sqlDumpFile = !$this->sqlDumpFile ? fopen('dump.sql', 'w') : $this->sqlDumpFile;
        self::$sqlDumpCreated = true;

        foreach ($comments as $comment) {
            if (isset($comment['WP_SWITCH_COMMENTS'])) {
                if (isset($comment['WP_SWITCH_COMMENTS']['answers'])) {
                    $this->generateSqlDump($comment['WP_SWITCH_COMMENTS']['answers']);
                }
                if (isset($comment['WP_SWITCH_COMMENTS']['metadatas'])) {
                    $this->generateSqlDump($comment['WP_SWITCH_COMMENTS']['metadatas'], 'wp_commentmeta');
                }
                unset($comment['WP_SWITCH_COMMENTS']);
            }
            [
                $keys,
                $values,
            ] = [
                implode(',', array_keys($comment)),
                implode(
                    ',',
                    array_map(
                        function ($value) {
                            $value = $this->db->quote($value);
                            return str_replace("\'", "''", $value);
                        },
                        array_values($comment)
                    )
                ),
            ];

            fwrite($this->sqlDumpFile, "INSERT INTO {$table} ({$keys}) VALUES({$values});" . PHP_EOL);
        }
    }

}