"""
Gera os dois pacotes de upload para a HostGator.

    python deploy-hostgator/empacotar.py

Dois cuidados que o zip do Windows (Compress-Archive) não toma e que já
causaram problema real no servidor:

1. Separador de caminho "/" e não "\\" — com barra invertida o extrator do
   cPanel cria arquivos com o nome literal "app\\Models\\Patient.php" em vez
   da árvore de pastas.
2. Modo 0644 nos arquivos. O zipfile do Python copia o modo do arquivo no
   Windows, que sai como 0666 (gravável por qualquer um) — em hospedagem
   compartilhada, código PHP gravável por outros é risco desnecessário.

O pacote do backend é uma LISTA EXPLÍCITA de arquivos, nunca uma varredura
de diretório: é isso que garante que banco de dados, .env, vendor/ e
storage/ jamais entrem no zip por acidente.
"""

import os
import subprocess
import zipfile

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DESTINO = os.path.join(RAIZ, 'deploy-hostgator')
MODO_ARQUIVO = 0o644

BACKEND = [
    # --- Alterados nesta versão (gestor observador, lista impressa,
    #     correção dos indicadores e novo dashboard) ---
    'app/Http/Controllers/Admin/DashboardController.php',
    'app/Http/Controllers/Admin/PatientDashboardController.php',
    'app/Http/Controllers/Admin/UserController.php',
    'app/Http/Controllers/AdmissionController.php',
    'app/Http/Controllers/DailyRoundController.php',
    'app/Http/Controllers/PhysicianController.php',
    'app/Http/Middleware/DenyObserverWrites.php',
    'app/Http/Requests/CloseAdmissionRequest.php',
    'app/Http/Requests/DashboardFilterRequest.php',
    'app/Models/User.php',
    'app/Policies/AdmissionPolicy.php',
    'app/Policies/PatientPolicy.php',
    'app/Providers/AppServiceProvider.php',
    'app/Services/AdmissionFilters.php',
    'app/Services/AmhsBilling.php',
    'app/Services/Percentiles.php',
    'bootstrap/app.php',
    'database/factories/UserFactory.php',
    'database/migrations/2026_09_03_100004_add_observer_role_to_users.php',
    'database/migrations/2026_09_04_100005_backfill_first_neurology_evaluation_at.php',
    'routes/api/admissions.php',
    'routes/web.php',

    # --- Da versão anterior, reenviados por segurança ---
    # Já devem estar no servidor. São byte a byte iguais aos que estão lá, então
    # reextrair não muda nada; entram para o pacote continuar completo caso
    # alguma extração anterior tenha pulado um arquivo em silêncio (já
    # aconteceu com a pasta assets/).
    'app/Console/Commands/NeurologiaBackup.php',
    'app/Exceptions/ConfirmedMedicalRecordException.php',
    'app/Http/Controllers/PatientController.php',
    'app/Http/Requests/ApplyMigrationsRequest.php',
    'app/Http/Requests/DeleteBackupRequest.php',
    'app/Http/Requests/DeleteUserRequest.php',
    'app/Http/Requests/StoreAdmissionRequest.php',
    'app/Http/Requests/StorePatientRequest.php',
    'app/Http/Requests/UpdateAdmissionRequest.php',
    'app/Http/Requests/UpdatePatientRequest.php',
    'app/Http/Controllers/Admin/SystemController.php',
    'app/Models/Admission.php',
    'app/Models/Patient.php',
    'app/Policies/UserPolicy.php',
    'app/Services/AdmissionExportService.php',
    'app/Services/BackupService.php',
    'database/migrations/2026_09_01_100001_add_attendance_number_to_admissions.php',
    'database/migrations/2026_09_01_100002_allow_patients_without_medical_record_number.php',
    'database/migrations/2026_09_02_100003_allow_patients_without_date_of_birth.php',
    'routes/api/admin.php',
    'routes/api/patients.php',
]

PROIBIDO = ('.env', '.sqlite3', 'vendor/', 'storage/', 'data/', 'backups/', 'exports/')


MODO_PASTA = 0o755

# Flag de diretório do MS-DOS. Alguns extratores olham só este bit, e não o
# modo Unix, para decidir se a entrada é pasta.
DOS_DIRETORIO = 0x10


def escrever_pastas(zf, nome_no_zip, ja_escritas):
    """
    Grava uma entrada explícita para cada pasta do caminho.

    Sem isso o extrator do cPanel NÃO cria a pasta que falta e simplesmente
    pula os arquivos dentro dela, em silêncio — foi o que aconteceu com
    `assets/` depois de ela ser apagada antes do upload, deixando o site
    sem CSS nem JS. Extratores que criam pastas sozinhos apenas ignoram
    estas entradas, então incluí-las é seguro em todo lugar.
    """
    partes = nome_no_zip.split('/')[:-1]
    for i in range(len(partes)):
        pasta = '/'.join(partes[:i + 1]) + '/'
        if pasta in ja_escritas:
            continue

        info = zipfile.ZipInfo(pasta)
        info.external_attr = (MODO_PASTA << 16) | DOS_DIRETORIO
        zf.writestr(info, b'')
        ja_escritas.add(pasta)


def escrever(zf, caminho_local, nome_no_zip, pastas_escritas):
    escrever_pastas(zf, nome_no_zip, pastas_escritas)

    info = zipfile.ZipInfo.from_file(caminho_local, nome_no_zip)
    info.external_attr = MODO_ARQUIVO << 16
    info.compress_type = zipfile.ZIP_DEFLATED
    with open(caminho_local, 'rb') as f:
        zf.writestr(info, f.read())


def conferir_lista_com_git():
    """
    A lista BACKEND é escrita à mão de propósito (é ela que garante que
    banco, .env e vendor nunca entrem no pacote), mas esquecer de incluir um
    arquivo alterado é fácil e o sintoma só aparece em produção — já
    aconteceu com um controller cujo endpoint novo a tela nova consumia.
    Aqui o git diz o que mudou e o empacotamento aborta se divergir.
    """
    try:
        saida = subprocess.run(
            ['git', 'status', '--porcelain', 'equipe/app'],
            cwd=RAIZ, capture_output=True, text=True, check=True,
        ).stdout
    except (OSError, subprocess.CalledProcessError):
        print('AVISO: git indisponível — lista de backend não conferida.')
        return

    alterados = {
        linha.split()[-1].replace('equipe/app/', '')
        for linha in saida.strip().splitlines()
        if linha.strip() and not linha.split()[-1].startswith('equipe/app/tests/')
    }

    faltando = sorted(alterados - set(BACKEND))
    if faltando:
        raise SystemExit(
            'ABORTADO: arquivos alterados fora da lista BACKEND:\n  '
            + '\n  '.join(faltando)
        )

    sobrando = sorted(set(BACKEND) - alterados)
    if sobrando:
        print('AVISO: na lista mas sem alteração pendente (ok se já commitado):')
        for arquivo in sobrando:
            print('  ' + arquivo)


def pacote_backend():
    base = os.path.join(RAIZ, 'equipe', 'app')
    saida = os.path.join(DESTINO, '1-backend-equipe-app.zip')

    pastas = set()
    with zipfile.ZipFile(saida, 'w') as zf:
        for rel in BACKEND:
            local = os.path.join(base, rel.replace('/', os.sep))
            if not os.path.isfile(local):
                raise SystemExit(f'ABORTADO: arquivo listado não existe — {rel}')
            escrever(zf, local, rel, pastas)

    return saida, len(BACKEND)


def pacote_frontend():
    base = os.path.join(RAIZ, 'public_html', 'visitas')
    saida = os.path.join(DESTINO, '2-frontend-public_html-visitas.zip')
    total = 0
    pastas = set()

    with zipfile.ZipFile(saida, 'w') as zf:
        for pasta, _, arquivos in os.walk(base):
            for nome in arquivos:
                local = os.path.join(pasta, nome)
                escrever(zf, local, os.path.relpath(local, base).replace(os.sep, '/'), pastas)
                total += 1

    return saida, total


def auditar(caminho):
    with zipfile.ZipFile(caminho) as zf:
        nomes = [i.filename for i in zf.infolist() if not i.is_dir()]
        pastas = [i.filename for i in zf.infolist() if i.is_dir()]

    if not pastas:
        raise SystemExit(f'ABORTADO: {os.path.basename(caminho)} sem entradas de pasta — '
                         'o extrator do cPanel não criaria as subpastas.')

    vazados = [n for n in nomes if any(p in n for p in PROIBIDO)]
    if vazados:
        raise SystemExit(f'ABORTADO: dados/segredos no pacote — {vazados}')

    invertida = [n for n in nomes if '\\' in n]
    if invertida:
        raise SystemExit(f'ABORTADO: separador inválido — {invertida}')


conferir_lista_com_git()

for caminho, total in (pacote_backend(), pacote_frontend()):
    auditar(caminho)
    tamanho = os.path.getsize(caminho) // 1024
    print(f'{os.path.basename(caminho)}: {total} arquivos, {tamanho} KB — auditado')
