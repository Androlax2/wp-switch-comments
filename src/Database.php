<?php

namespace Antipodes\Wordpress\SwitchComments;

use PDO;

class Database extends PDO
{

    public function __construct(string $sqlDump, $dsn, $username = null, $password = null, $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        // TODO : Créer backup des databases
        $this->clearDatabase();
        $this->fillDatabase($sqlDump);
    }

    /**
     * Retrieve the comments associated for each post ids.
     *
     * @param array $postIds
     *
     * @return array
     */
    public function getComments(array $postIds): array
    {
        // Récupérer tous les commentaires dans la table 'wp_comments' qui ont le 'comment_post_ID' qui est équivalent à un des ID clé du tableau postIds sans les réponses

        $ids = implode(',', array_keys($postIds));
        $commentsStatement = $this->prepare("SELECT * FROM wp_comments WHERE comment_post_ID IN ({$ids}) AND comment_parent = 0");

        if (!$commentsStatement->execute()) {
            return [];
        }

        $comments = $commentsStatement->fetchAll(PDO::FETCH_ASSOC);

        foreach ($comments as $key => $comment) {
            // Créer un tableau avec les commentaires en haut et les réponses en bas etc.. etc..
            $answers = $this->getAnswersOfComment($comment['comment_ID']);
            if ($answers && sizeof($answers) > 0) {
                $comments[$key]['WP_SWITCH_COMMENTS']['answers'] = $answers;
            }

            // Récupérer les metadatas de chaque commentaire
            $comments[$key]['WP_SWITCH_COMMENTS']['metadatas'] = $this->getMetadatasOfComment($comment['comment_ID']);
        }

        return $comments;
    }

    /**
     * Get answers of a comment.
     *
     * @param int $commentId
     *
     * @return array
     */
    private function getAnswersOfComment(int $commentId): array
    {
        $answersStatement = $this->prepare("SELECT * FROM wp_comments WHERE comment_parent = {$commentId}");

        if (!$answersStatement->execute()) {
            return [];
        }

        $answers = $answersStatement->fetchAll(PDO::FETCH_ASSOC);

        if (!$answers && sizeof($answers) === 0) {
            return [];
        }

        foreach ($answers as $key => $answer) {
            $answersOfAnswers = $this->getAnswersOfComment($answer['comment_ID']);
            if ($answersOfAnswers && sizeof($answersOfAnswers) > 0) {
                $answers[$key]['WP_SWITCH_COMMENTS']['answers'] = $answersOfAnswers;
            }

            $answers[$key]['WP_SWITCH_COMMENTS']['metadatas'] = $this->getMetadatasOfComment($answer['comment_ID']);
        }

        return $answers;
    }

    /**
     * Clear database.
     */
    private function clearDatabase(): void
    {
        $this->query('SET foreign_key_checks = 0');
        $result = $this->query('SHOW TABLES');
        if ($result) {
            foreach ($result->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $this->query("DROP TABLE IF EXISTS {$table}");
            }
        }
        $this->query('SET foreign_key_checks = 1');
    }

    /**
     * Add SQL dump to the database.
     */
    private function fillDatabase(string $sql): void
    {
        $this->query($sql);
    }

    /**
     * Get metadatas of a comment.
     *
     * @param int $commentId
     *
     * @return array
     */
    private function getMetadatasOfComment(int $commentId): array
    {
        $metadatas = [
            [
                'comment_id' => "{$commentId}",
                'meta_key'   => 'WP_SWITCH_COMMENTS',
                'meta_value' => '1',
            ],
        ];
        $metadatasStatement = $this->prepare("SELECT * FROM wp_commentmeta WHERE comment_id IN ({$commentId})");

        if (!$metadatasStatement->execute()) {
            return $metadatas;
        }

        $metas = $metadatasStatement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($metas as &$meta) {
            unset($meta['meta_id']);
        }

        if (!$metas && sizeof($metas) === 0) {
            return $metadatas;
        }

        return array_merge($metadatas, $metas);
    }

}