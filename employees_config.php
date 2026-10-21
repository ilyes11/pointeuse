<?php
/* Employés suivis (ne sont PAS dans la table students) : on enregistre juste leurs pointages
   dans la table Supabase employee_punches pour avoir l'historique (retards / absences). */

// IDs de la pointeuse des employés
define('EMP_IDS', '450,451,452,453,454,455,456,457,1,458,459,460,461,462,463');

// Noms affichés (optionnel). Mets le nom à côté de l'ID, sinon la page affiche "ID 450".
$EMP_NAMES = array(
    // '450' => 'Nom Prénom',
    // '451' => 'Nom Prénom',
);

// Jours travaillés : 1 = lundi ... 6 = samedi, 7 = dimanche
$WORK_DAYS = array(1, 2, 3, 4, 5, 6);

function empIdList() {
    return array_filter(array_map('trim', explode(',', EMP_IDS)), 'strlen');
}
