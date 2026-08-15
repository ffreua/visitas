# DATABASE SCHEMA — Neurologia Hospitalar

Banco: SQLite, arquivo em `~/equipe/data/neurologia.sqlite3` (fora de `public_html`).

PRAGMAs de conexão obrigatórios: `foreign_keys=ON`, `journal_mode=WAL`, `busy_timeout=5000`.

Este documento é a referência canônica do modelo de dados. Migrations devem seguir exatamente esta estrutura; qualquer divergência deve ser corrigida aqui primeiro.

## Princípio central

`Patient` (paciente, longitudinal) é separado de `Admission` (episódio/internação/acompanhamento). Um paciente tem N admissions. Reinternação = novo `Admission`, nunca sobrescreve o anterior.

## users
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| uuid | string, unique | |
| full_name | string | |
| crm | string, nullable | |
| username | string, unique | |
| password | string (hash) | Argon2id se disponível, senão bcrypt padrão Laravel |
| role | enum: ADMIN, PHYSICIAN | |
| must_change_password | boolean, default true | |
| active | boolean, default true | nunca hard delete se já usado em atendimento |
| last_login_at | timestamp, nullable | |
| timestamps | | |

## patients
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| uuid | string, unique | |
| medical_record_number | string, unique, indexed, normalizado | não usar CPF |
| full_name | string | |
| date_of_birth | date | validação: < hoje |
| timestamps | | |

## health_plans
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| name | string | |
| normalized_name | string, indexed | para autocomplete accent/case-insensitive |
| aliases | json, nullable | |
| active | boolean, default true | nunca deletar plano já usado; apenas active=false |
| timestamps | | |

## medical_specialties
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| name | string | |
| normalized_name | string, indexed | |
| active | boolean, default true | |

## cid10
| campo | tipo | notas |
|---|---|---|
| code | string, PK | |
| description | string | |
| category | string | primeiros 3 caracteres |
| chapter | string, nullable | |
| normalized_description | string, indexed | |

Importado localmente (seeder/import command), sem dependência de API externa em runtime clínico.

## admissions
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| uuid | string, unique | |
| patient_id | FK → patients | indexed |
| admission_at | datetime | |
| hospital_discharge_at | datetime, nullable | ≠ encerramento da Neurologia |
| neurology_followup_started_at | datetime | |
| neurology_followup_closed_at | datetime, nullable | |
| status | enum: ACTIVE, CLOSED | indexed |
| care_type | enum: INSTITUTIONAL, INTERCONSULT | indexed |
| followup_mode | enum: ONGOING, SINGLE_EVALUATION | indexed |
| payer_type | enum: HEALTH_PLAN, PRIVATE | indexed |
| health_plan_id | FK → health_plans, nullable | obrigatório se payer_type=HEALTH_PLAN; null se PRIVATE |
| health_plan_name_snapshot | string, nullable | snapshot do nome no momento do registro |
| origin | string, nullable | |
| unit | string, nullable | |
| bed | string, nullable | |
| requesting_specialty_id | FK → medical_specialties, nullable | obrigatório se INTERCONSULT; indexed |
| consult_reason | text, nullable | |
| consult_priority | string, nullable | |
| consult_requested_at | datetime, nullable | obrigatório se INTERCONSULT |
| first_neurology_evaluation_at | datetime, nullable | |
| brief_history | text, nullable | candidato a criptografia de aplicação |
| discharge_outcome | text, nullable | |
| followup_plan_documented | text, nullable | |
| created_by | FK → users | |
| updated_by | FK → users, nullable | |
| version | integer, default 1 | optimistic locking |
| deleted_at | timestamp, nullable | SoftDeletes |
| deleted_by | FK → users, nullable | |
| deletion_reason | string, nullable | |
| timestamps | | |

Validações de negócio (Form Requests):
- `neurology_followup_closed_at >= neurology_followup_started_at`
- `hospital_discharge_at >= admission_at`
- INTERCONSULT ⇒ `requesting_specialty_id` e `consult_requested_at` obrigatórios
- `payer_type=HEALTH_PLAN` ⇒ `health_plan_id` obrigatório; `payer_type=PRIVATE` ⇒ `health_plan_id` deve ser null
- Encerramento ⇒ diagnóstico final obrigatório
- Um paciente só pode ter 1 admission com status=ACTIVE por vez (constraint de aplicação, verificado antes de criar)

## admission_diagnoses
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| admission_id | FK → admissions, indexed | |
| phase | enum: SUSPECTED, FINAL | indexed |
| cid_code | FK → cid10.code | |
| description_snapshot | string | |
| is_primary | boolean | 1 principal, N secundários por phase |
| created_by | FK → users | |
| created_at | timestamp | |

## pending_items
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| admission_id | FK → admissions, indexed | |
| description | string | |
| status | enum: OPEN, DONE, CANCELLED | indexed |
| created_by | FK → users | |
| created_at | timestamp | |
| resolved_by | FK → users, nullable | |
| resolved_at | timestamp, nullable | |

## daily_rounds
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| admission_id | FK → admissions, indexed | |
| round_date | date, indexed | |
| assigned_physician_id | FK → users, nullable, indexed | |
| assigned_by | FK → users, nullable | |
| assigned_at | timestamp, nullable | |
| completed_by | FK → users, nullable | |
| completed_at | timestamp, nullable | |
| daily_note | text, nullable | |
| timestamps | | |

Constraint: `UNIQUE(admission_id, round_date)`.

## audit_logs
| campo | tipo | notas |
|---|---|---|
| id | PK | |
| timestamp | timestamp | |
| user_id | FK → users, nullable | |
| action | string, indexed | ver lista de ações no PRD (LOGIN, CREATE_ADMISSION, SOFT_DELETE, PERMANENT_DELETE, etc.) |
| entity_type | string | |
| entity_id | string | |
| changed_fields | json, nullable | somente nomes de campos alterados, nunca PHI completo |
| request_id | string, nullable | |
| ip_hash | string, nullable | |

Não duplicar PHI (história completa, nome, pendências inteiras) no audit log.

## Índices obrigatórios
`patients.medical_record_number`; `admissions.status`, `.patient_id`, `.admission_at`, `.deleted_at`, `.care_type`, `.followup_mode`, `.payer_type`, `.health_plan_id`, `.requesting_specialty_id`; `daily_rounds.round_date`, `.assigned_physician_id`; `admission_diagnoses.cid_code`, `.phase`; `pending_items.status`.

## Concorrência
Coluna `version` em `admissions` para optimistic locking. Conflito de edição concorrente deve retornar 409 com mensagem "Este atendimento foi atualizado por outro usuário. Recarregue os dados antes de salvar." — nunca sobrescrever silenciosamente.
