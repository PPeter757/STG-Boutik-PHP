# Boutique - Application de gestion des ventes (PHP + MySQL)

Contenu:
- index.php : page de connexion
- logout.php : déconnexion
- dashboard.php : tableau de bord simple
- produits.php : liste + ajout produit
- produit_edit.php : modifier / supprimer produit
- ventes.php : créer une vente (panier JS)
- vente_show.php : afficher une vente / reçu imprimable
- clients.php : CRUD clients
- create_admin.php : script pour créer un admin avec mot de passe hashé
- includes/db.php : connexion PDO
- includes/auth.php : vérification session
- assets/: dossiers pour JS/CSS
- sql/init.sql : script SQL pour créer la base de données et les tables

Instructions rapide:
1. Installer XAMPP (ou un serveur LAMP).
2. Copier le dossier `boutique` dans `C:/xampp/htdocs/` (Windows) ou le dossier www/ sur Linux.
3. Démarrer Apache et MySQL.
4. Importer `sql/init.sql` via phpMyAdmin ou exécuter le script SQL.
5. Ouvrir `create_admin.php` dans ton navigateur pour créer un utilisateur admin (supprime le fichier après usage).
6. Accéder à `http://localhost/boutique/` et se connecter.

Remarques:
- Change les identifiants DB dans includes/db.php si nécessaire.
- Sécurise le dossier pour un déploiement en production (HTTPS, protection des fichiers).
