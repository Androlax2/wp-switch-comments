# Etapes :

- Créer un fichier `.env` à partir du `.env.example`
- Remplir ce fichier avec les infos de base de données locales
    - **ATTENTION**, cette base de données doit être une base de données avec rien dedans, elle sert juste à faire des requêtes. Elle sera
      vidé à chaque lancement de commande.
- Lancer `composer install`
- Créer un fichier `config/database.sql` contenant le dump SQL de la base de donnée de l'ancien Wordpress
- Remplir le fichier `config/post-ids.php`
    - C'est un tableau qui contient une `clé` et une `valeur`, il permet de changer l'ID de l'ancien Wordpress pour le mettre sur le nouveau
      Wordpress
        - La clé est l'ID du post de l'ancien Wordpress
        - La valeur est l'ID du post du nouveau Wordpress
- Remplir le fichier `config/authors.php`
    - C'est un tableau qui contient une `clé` et une `valeur`, il permet de changer les utilisateurs du précédent site et de les modifier
      sur le nouveau.
        - La clé est l'ID de l'auteur de l'ancien Wordpress
        - La valeur est un tableau :
            - id : L'ID de l'auteur sur le nouveau Wordpress
            - name : Le nom de l'auteur sur le nouveau Wordpress
            - email : L'email de l'auteur sur le nouveau Wordpress
- Récupérer `l'ID` du dernier post dans la table `wp_comments` du nouveau site Wordpress
- Lancer la commande : `php artisan wp:switch-comments` avec `l'ID + 1` après la commande. Cette ID permet de simuler l'auto incrémentation
  sur le nouveau site Wordpress des commentaires.
- Récupérer les requêtes SQL générées dans le fichier `dump.sql` qui est créé grâce à cette commande.