-- À exécuter UNE FOIS dans Supabase (SQL Editor) du projet utilisé par Render (SB_URL).
create table if not exists public.employee_punches (
  id          bigint generated always as identity primary key,
  pin         integer not null,                       -- ID de la pointeuse (450, 451, ..., 1)
  device_time text    not null,                       -- heure brute envoyée par la pointeuse (sert à éviter les doublons)
  received_at timestamptz not null default now(),     -- heure réelle de réception (utilisée par la page)
  unique (pin, device_time)
);
create index if not exists employee_punches_received_idx on public.employee_punches (received_at);

-- Accessible seulement avec la clé service_role (celle de SB_KEY) : pas d'accès public.
alter table public.employee_punches enable row level security;
