-- Journal d'activité : filtres par période et par type d'action (« qui a fait quoi »)
ALTER TABLE activity_log
    ADD KEY idx_al_date (created_at),
    ADD KEY idx_al_action_date (action, created_at);
