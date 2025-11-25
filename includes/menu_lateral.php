<?php
$role = $_SESSION['nom_role']; // ou $_SESSION['role_id']
?>
<aside class="fixed top-0 left-0 h-full w-64 bg-white shadow flex flex-col overflow-y-auto">
  <!-- Logo / Nom -->
  <div class="flex items-center justify-center gap-2 p-4 border-b">
    <img src="assets/NPH_logo.png" alt="Logo" class="h-10 w-10 object-contain">
    <span class="text-xl font-bold text-blue-900">Ste. Germaine</span>
  </div>

  <!-- Menu principal -->
  <nav class="flex-1">
    <ul class="flex flex-col p-2 gap-1">
      <!-- Accueil / Dashboard -->
      <li>
        <a href="dashboard.php" class="flex items-center gap-3 p-3 rounded hover:bg-blue-100 transition
                    <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'bg-blue-200 font-semibold' : '' ?>">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M13 5v6h6" />
          </svg>
          <span>Accueil</span>
        </a>
      </li>
      <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
      <!-- Menu Inventaire : uniquement pour administrateurs -->
      <?php if ($role === 'administrateur'): ?>
        <li>
          <a href="produits.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'produits.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0l-8 5-8-5m16 0v6a2 2 0 01-2 2H6a2 2 0 01-2-2v-6" />
            </svg>
            <span>Registre Produits</span>
          </a>
        </li>
        <li>
          <a href="ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Passer commandes  -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13L17 13M9 21h6" />
            </svg>
            <span>Passer Commande</span>
          </a>
        </li>
        <li>
          <a href="liste_ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'liste_ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Historique Ventes -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8M8 11h8M8 15h8M4 19h16V5H4v14z" />
            </svg>
            <span>Historique Ventes</span>
          </a>
        </li>
        <li>
          <a href="stock.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'stock.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Voir Stock -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            <span>Voir Stock</span>
          </a>
        </li>
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
        <li>
          <a href="rapport_ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6h5V9H4v8h5z" />
            </svg>
            <span>Rapport Ventes</span>
          </a>
        </li>
        <li>
          <a href="rapport_commandes_sur_commande.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_commandes_sur_commande.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6h5V9H4v8h5z" />
            </svg>
            <span>Rapport Commandes</span>
          </a>
        </li>
        <li>
          <a href="rapport_stock.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_stock.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13L17 13M7 13H5.4M17 13l1.5 7M9 21h6" />
            </svg> <span>Rapport Stock</span>
          </a>
        </li>
        <li>
          <a href="rapport_clients.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_clients.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1112 21a9 9 0 01-6.879-3.196zM15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>Rapport Clients</span>
          </a>
        </li>
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
        <li>
          <a href="clients.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'clients.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Clients -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m0-4a4 4 0 100-8 4 4 0 000 8zm8 0a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
            <span>Gestion Clients</span>
          </a>
        </li>
        <li>
          <a href="users_list.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'users_list.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Utilisateurs -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zM6 20v-2a4 4 0 014-4h4a4 4 0 014 4v2" />
            </svg>
            <span>Gestion Utilisateurs</span>
          </a>
        </li>
        <!-- Profil / Déconnexion : visible pour tous -->
         <li>
          <a href="profil.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'profil.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Utilisateurs -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zM6 20v-2a4 4 0 014-4h4a4 4 0 014 4v2" />
            </svg>
            <span>Gestion Profil</span>
          </a>
        </li>
        <!-- Rapports / Analyses -->
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
      <?php endif; ?>

      <!-- Menu Inventaire : uniquement pour Vendeur et Caissier -->
      <?php if (in_array($role, ['vendeur', 'caissier'])): ?>
        <li>
          <a href="ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13L17 13M9 21h6" />
            </svg>
            <span>Passer Commande</span>
          </a>
        </li>
        <li> <a href="liste_ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'liste_ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Historique Ventes -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8M8 11h8M8 15h8M4 19h16V5H4v14z" />
            </svg> <span>Historique Ventes</span>
          </a>
        </li>
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
      <?php endif; ?>

      <!-- Rapports : uniquement pour Superviseur -->
      <?php if ($role === 'superviseur'): ?>
        <li>
          <a href="produits.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'produits.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0l-8 5-8-5m16 0v6a2 2 0 01-2 2H6a2 2 0 01-2-2v-6" />
            </svg>
            <span>Registre Produits</span>
          </a>
        </li>
        <li> <a href="liste_ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'liste_ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Historique Ventes -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8M8 11h8M8 15h8M4 19h16V5H4v14z" />
            </svg> <span>Historique Ventes</span>
          </a>
        </li>
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
        <li>
          <a href="rapport_ventes.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_ventes.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6h5V9H4v8h5z" />
            </svg>
            <span>Rapport Ventes</span>
          </a>
        </li>
        <li>
          <a href="rapport_commandes_sur_commande.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_commandes_sur_commande.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6h6v6h5V9H4v8h5z" />
            </svg>
            <span>Rapport Commandes</span>
          </a>
        </li>
        <li>
          <a href="rapport_stock.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_stock.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.5 7h13L17 13M7 13H5.4M17 13l1.5 7M9 21h6" />
            </svg> <span>Rapport Stock</span>
          </a>
        </li>
        <li>
          <a href="rapport_clients.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'rapport_clients.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1112 21a9 9 0 01-6.879-3.196zM15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>Rapport Clients</span>
          </a>
        </li>
        <h3 class="px-3 py-2 text-white bg-gray-300 uppercase font-semibold text-sm gap-3 p-2 rounded"></h3>
        <li>
          <a href="clients.php" class="menu-link flex items-center gap-3 p-2 rounded hover:bg-blue-100 transition <?= basename($_SERVER['PHP_SELF']) === 'stock.php' ? 'bg-blue-200 font-semibold' : '' ?>">
            <!-- Icon Clients -->
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m0-4a4 4 0 100-8 4 4 0 000 8zm8 0a4 4 0 100-8 4 4 0 000 8z" />
            </svg>
            <span>Gestion Clients</span>
          </a>
        </li>
      <?php endif; ?>
      <li>
        <a href="logout.php" class="flex items-center gap-3 p-3 rounded hover:bg-red-100 transition">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7" />
          </svg>
          <span>Se déconnecter</span>
        </a>
      </li>
    </ul>
  </nav>
</aside>