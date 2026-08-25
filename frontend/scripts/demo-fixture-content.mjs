/**
 * Localized content for generate-demo-fixture.mjs — 4 locale-specific
 * employee/manager pairs, each with a 2-cycle narrative (a "struggle" cycle
 * followed by a "resolution" cycle) so the demo has real history to show
 * off trend sparklines (mood/workload in AnketaList's grouped view, goal
 * progress in Report.svelte) and outcome carry-forward, not just one
 * isolated anketa.
 *
 * The generation script always drives the browser UI in English — only the
 * *typed* content here (free-text answers, comments, outcomes, goal text)
 * is locale-specific; question labels/buttons are rendered from the
 * viewer's own selected UI locale at view time, independent of which
 * account is used (see CLAUDE.md's Phase 6h notes). Radio/checkbox VALUES
 * below (e.g. 'good', 'too_much') are the app's fixed internal option
 * values, not translated text.
 *
 * Narrative: cycle 1 is an overloaded, underscoped migration project with a
 * stalled cross-team goal; cycle 2 is the payoff (shipped, mentored,
 * outcome resolved, goal checkpoint improves from at_risk to on_track) —
 * chosen deliberately so both the mood/workload trend and the goal-progress
 * trend show a genuine, believable improvement across cycles.
 */

export const DEMO_LOCALES = ['en', 'ru', 'lv', 'es'];

export const CONTENT = {
  en: {
    employeeEmail: 'demo-employee@example.com',
    employeeName: 'Alex Morgan',
    managerEmail: 'demo-manager@example.com',
    managerName: 'Jordan Blake',
    goal: {
      title: 'Lead one cross-team project end to end',
      description:
        'Own scoping and delivery of a project spanning at least two teams.',
    },
    cycle1: {
      employee: {
        moodNow: 'neutral',
        moodTrend: 'worse',
        moodText:
          "This project has dragged on longer than expected, and I'm feeling the pressure of a tight deadline with a lot of unknowns still unresolved.",
        feelings: ['overwhelmed', 'anxious'],
        feelingsText:
          'Juggling the migration work with a lot of ad-hoc requests from other teams — hard to find real focus time.',
        workloadNow: 'too_much',
        workloadTrend: 'more',
        workloadText:
          'The billing export migration turned out to be a lot bigger than originally scoped. Could use help figuring out what can wait.',
        growth: [
          "Learned just how tangled the billing export's downstream dependencies are — a good case study in why data lineage documentation actually matters.",
          'Paired with a senior engineer on a tricky migration edge case and picked up some new debugging techniques.',
        ],
        harder:
          'Cross-team dependency requests still go through a lot of back-and-forth over Slack before anyone commits to a timeline. A shared intake process would save a lot of chasing.',
        achievements: [
          'Mapped out the full scope of the billing export migration, including several edge cases nobody had flagged before.',
        ],
        whatElse: [
          'Want to talk about a realistic timeline for the migration now that we know how much bigger it actually is.',
        ],
      },
      manager: {
        howWasPeriod:
          "A tough period — the billing export migration turned out to be more complex than anyone expected, and Priya's been carrying a heavy load just figuring out the real scope.",
        feedback:
          "The thoroughness in scoping this out has been genuinely valuable, even though it's meant a slower start than we'd like. I'd like us to re-baseline the timeline together rather than you absorbing the pressure alone.",
        howCanIHelp:
          "I'll push back on some of the ad-hoc requests coming in from other teams so there's more room to actually focus.",
        achievements: [
          'Did the hard, unglamorous work of untangling a migration that was underscoped from the start — much better to find that out now than mid-rollout.',
        ],
        whatElse: [
          'Want to align on a realistic new timeline and figure out whether we need another pair of hands.',
        ],
      },
      comment:
        "Thanks for flagging this clearly — let's dig into the timeline together, you shouldn't have to carry this alone.",
      outcome:
        'Priya to draft a lightweight cross-team dependency intake template by next meeting.',
      checkpoint: {
        text: 'Scoping conversations with the platform team have stalled — still waiting on their prioritization.',
        tag: 'at_risk',
      },
    },
    cycle2: {
      employee: {
        moodNow: 'good',
        moodTrend: 'better',
        moodText:
          "Shipped the billing export migration this period, which had been hanging over me for a while — feeling a lot lighter now that it's out.",
        feelings: ['motivated', 'confident'],
        feelingsText:
          'Onboarding the new hire went well — good reminder that I actually enjoy the mentoring side of things.',
        workloadNow: 'just_right',
        workloadTrend: 'less',
        workloadText:
          "Workload dropped a bit now that the migration's done. Good time to pick up something new if there's a fit.",
        growth: [
          'Learned that short recorded walkthroughs get way more engagement than written code-review comments — switching to that for the trickier reviews.',
          'Sat in on a postmortem for the first time — useful to see how the team traces an incident back to root cause.',
        ],
        harder:
          'Documentation for cross-team services is scattered across five different wikis — still no single source of truth, even after raising it a few times.',
        achievements: [
          'Shipped the billing export migration end to end, ahead of the original estimate.',
          "Mentored the new hire through their first on-call rotation without a single escalation.",
        ],
        whatElse: [
          'Interested in leading a cross-team project next quarter — want to talk about what that path looks like.',
        ],
      },
      manager: {
        howWasPeriod:
          "Strong period — Priya owned the billing export migration from design through rollout and it landed cleanly. Also stepped up on mentoring without being asked to.",
        feedback:
          "Ownership and follow-through are excellent — I don't worry about migration work once it's assigned to Priya. Would love to see more proactive updates in standup rather than waiting to be asked; the work itself is rarely the issue, it's visibility.",
        howCanIHelp:
          "I raised the cross-team dependency-intake idea with the platform team lead — they're open to a shared template once you've got a draft. I'll help circulate it once it's ready.",
        achievements: [
          'Owned the billing export migration from design to ship with zero rollback.',
          "First-time mentor for a new hire's on-call rotation — smooth ramp-up, no incidents.",
        ],
        whatElse: [
          'Ready to talk through what leading a cross-team project would actually look like for Priya next quarter.',
        ],
      },
      comment: 'Congrats on shipping this ahead of schedule!',
      outcomeNew:
        "Jordan to share the finalized cross-team intake template with the platform team once Priya's draft is ready.",
      checkpoint: {
        text: 'Kicked off scoping conversations with the platform team.',
        tag: 'on_track',
      },
    },
  },

  ru: {
    employeeEmail: 'demo-employee-ru@example.com',
    employeeName: 'Анна Соколова',
    managerEmail: 'demo-manager-ru@example.com',
    managerName: 'Дмитрий Волков',
    goal: {
      title: 'Довести один межкомандный проект от начала до конца',
      description:
        'Взять на себя постановку задач и реализацию проекта, охватывающего минимум две команды.',
    },
    cycle1: {
      employee: {
        moodNow: 'neutral',
        moodTrend: 'worse',
        moodText:
          'Этот проект затянулся дольше, чем ожидалось, и я чувствую давление сжатых сроков — а неопределённости пока даже не убавилось.',
        feelings: ['overwhelmed', 'anxious'],
        feelingsText:
          'Приходится совмещать работу над миграцией с кучей внеплановых запросов от других команд — сложно найти время на настоящую сосредоточенную работу.',
        workloadNow: 'too_much',
        workloadTrend: 'more',
        workloadText:
          'Миграция экспорта биллинга оказалась намного масштабнее, чем изначально планировалось. Не помешала бы помощь в расстановке приоритетов.',
        growth: [
          'Узнала, насколько запутаны зависимости экспорта биллинга — хороший пример того, почему документация о происхождении данных действительно важна.',
          'Работала в паре со старшим инженером над сложным пограничным случаем миграции — освоила несколько новых техник отладки.',
        ],
        harder:
          'Межкомандные запросы по-прежнему требуют долгой переписки в Slack, прежде чем кто-то возьмёт на себя обязательство по срокам. Общий процесс приёма таких запросов сэкономил бы много времени на выяснения.',
        achievements: [
          'Полностью прояснила объём миграции экспорта биллинга, включая несколько пограничных случаев, о которых раньше никто не упоминал.',
        ],
        whatElse: [
          'Хочу обсудить реалистичные сроки миграции — теперь понятно, что она оказалась намного крупнее, чем думали.',
        ],
      },
      manager: {
        howWasPeriod:
          'Непростой период — миграция экспорта биллинга оказалась сложнее, чем кто-либо ожидал, и Марии пришлось нести немалую нагрузку, просто чтобы разобраться в реальном объёме работ.',
        feedback:
          'Тщательность, с которой был проработан объём задачи, оказалась по-настоящему ценной, хотя это и означало более медленный старт, чем хотелось бы. Хочу, чтобы мы вместе пересмотрели сроки, а не чтобы ты одна несла это давление.',
        howCanIHelp:
          'Возьму на себя часть внеплановых запросов от других команд, чтобы освободить больше времени для настоящей сосредоточенной работы.',
        achievements: [
          'Проделала непростую, невидимую работу по распутыванию миграции, объём которой изначально был занижен — гораздо лучше узнать об этом сейчас, чем в разгар внедрения.',
        ],
        whatElse: [
          'Хочу согласовать реалистичные новые сроки и понять, не нужна ли нам ещё одна пара рук.',
        ],
      },
      comment:
        'Спасибо, что чётко это обозначила — давай вместе разберёмся со сроками, тебе не нужно нести это в одиночку.',
      outcome:
        'Мария подготовит черновик лёгкого шаблона приёма межкомандных запросов к следующей встрече.',
      checkpoint: {
        text: 'Обсуждения с командой платформы застопорились — всё ещё ждём их приоритизации.',
        tag: 'at_risk',
      },
    },
    cycle2: {
      employee: {
        moodNow: 'good',
        moodTrend: 'better',
        moodText:
          'В этот период выпустила миграцию экспорта биллинга, которая давно висела надо мной — стало значительно легче, когда она наконец вышла.',
        feelings: ['motivated', 'confident'],
        feelingsText:
          'Онбординг нового сотрудника прошёл хорошо — хорошее напоминание, что мне действительно нравится наставничество.',
        workloadNow: 'just_right',
        workloadTrend: 'less',
        workloadText:
          'Нагрузка немного снизилась теперь, когда миграция завершена. Хороший момент, чтобы взяться за что-то новое, если найдётся подходящая задача.',
        growth: [
          'Поняла, что короткие записанные видео-обзоры кода вовлекают гораздо больше, чем письменные комментарии в code review — перехожу на этот формат для сложных ревью.',
          'Впервые присутствовала на разборе инцидента — полезно было увидеть, как команда прослеживает первопричину.',
        ],
        harder:
          'Документация по межкомандным сервисам разбросана по пяти разным вики — единого источника истины всё ещё нет, хотя я поднимала этот вопрос уже несколько раз.',
        achievements: [
          'Полностью выпустила миграцию экспорта биллинга, раньше исходной оценки срока.',
          'Провела нового сотрудника через его первое дежурство без единой эскалации.',
        ],
        whatElse: [
          'Интересно возглавить межкомандный проект в следующем квартале — хочу обсудить, как это может выглядеть.',
        ],
      },
      manager: {
        howWasPeriod:
          'Сильный период — Мария взяла на себя миграцию экспорта биллинга от проектирования до внедрения, и всё прошло гладко. Также сама, без напоминаний, включилась в наставничество.',
        feedback:
          'Ответственность и доведение дел до конца — на высоте: я не переживаю за миграционные задачи, если они поручены Марии. Хотелось бы видеть больше проактивных апдейтов на стендапе, а не ждать, пока их спросят — сама работа почти никогда не проблема, дело в видимости.',
        howCanIHelp:
          'Поднял идею о процессе приёма межкомандных запросов перед руководителем команды платформы — они открыты к общему шаблону, как только у тебя будет черновик. Помогу распространить его, когда он будет готов.',
        achievements: [
          'Взяла на себя миграцию экспорта биллинга от проектирования до релиза без единого отката.',
          'Впервые выступила наставником для нового сотрудника на дежурстве — плавный старт, без инцидентов.',
        ],
        whatElse: [
          'Готов обсудить, как на практике могло бы выглядеть лидерство Марии в межкомандном проекте в следующем квартале.',
        ],
      },
      comment: 'Поздравляю с тем, что выпустила это раньше срока!',
      outcomeNew:
        'Дмитрий поделится финальной версией шаблона приёма запросов с командой платформы, как только черновик Марии будет готов.',
      checkpoint: {
        text: 'Начали обсуждения с командой платформы.',
        tag: 'on_track',
      },
    },
  },

  lv: {
    employeeEmail: 'demo-employee-lv@example.com',
    employeeName: 'Anna Kalniņa',
    managerEmail: 'demo-manager-lv@example.com',
    managerName: 'Jānis Bērziņš',
    goal: {
      title: 'Novadīt vienu starpkomandu projektu no sākuma līdz beigām',
      description:
        'Uzņemties vismaz divas komandas aptveroša projekta plānošanu un īstenošanu.',
    },
    cycle1: {
      employee: {
        moodNow: 'neutral',
        moodTrend: 'worse',
        moodText:
          'Šis projekts ir ievilcies ilgāk, nekā gaidīts, un jūtu spiedienu no saspringtā termiņa, kamēr daudz kas joprojām nav skaidrs.',
        feelings: ['overwhelmed', 'anxious'],
        feelingsText:
          'Nākas apvienot migrācijas darbu ar daudziem ārpuskārtas pieprasījumiem no citām komandām — grūti atrast laiku patiesi koncentrētam darbam.',
        workloadNow: 'too_much',
        workloadTrend: 'more',
        workloadText:
          'Rēķinu eksporta migrācija izrādījās daudz apjomīgāka, nekā sākotnēji plānots. Noderētu palīdzība, izdomājot, kas var pagaidīt.',
        growth: [
          'Uzzināju, cik sarežģītas ir rēķinu eksporta migrācijas atkarības — labs piemērs tam, kāpēc datu izcelsmes dokumentācija tiešām ir svarīga.',
          'Strādāju kopā ar pieredzējušu inženieri pie sarežģīta migrācijas gadījuma un apguvu jaunas atkļūdošanas metodes.',
        ],
        harder:
          'Starpkomandu pieprasījumi joprojām prasa daudz saraksti Slack, pirms kāds apņemas ievērot termiņu. Kopīgs pieprasījumu pieņemšanas process ietaupītu daudz laika.',
        achievements: [
          'Precīzi apzināju rēķinu eksporta migrācijas pilnu apjomu, ieskaitot vairākus gadījumus, ko iepriekš neviens nebija pamanījis.',
        ],
        whatElse: [
          'Vēlos parunāt par reālistisku migrācijas grafiku, tagad, kad zinām, cik tā patiesībā ir apjomīgāka.',
        ],
      },
      manager: {
        howWasPeriod:
          'Sarežģīts periods — rēķinu eksporta migrācija izrādījās sarežģītāka, nekā kāds gaidīja, un Laurai nācās uzņemties lielu slodzi, tikai lai noskaidrotu reālo apjomu.',
        feedback:
          'Rūpība, ar kādu tika izvērtēts uzdevuma apjoms, ir bijusi patiesi vērtīga, lai gan tas nozīmēja lēnāku sākumu, nekā vēlētos. Vēlos, lai mēs kopā no jauna izvērtētu grafiku, nevis lai tu viena nes šo spiedienu.',
        howCanIHelp:
          'Uzņemšos daļu no ārpuskārtas pieprasījumiem no citām komandām, lai atbrīvotu vairāk laika patiesai koncentrēšanās.',
        achievements: [
          'Paveica smago, nepamanāmo darbu, atšķetinot migrāciju, kuras apjoms sākotnēji bija nepietiekami novērtēts — daudz labāk to atklāt tagad, nevis ieviešanas vidū.',
        ],
        whatElse: [
          'Vēlos vienoties par reālistisku jaunu grafiku un saprast, vai mums vajadzīgas vēl vienas rokas.',
        ],
      },
      comment:
        'Paldies, ka to skaidri paudi — atrisināsim grafiku kopā, tev nav jānes tas vienai.',
      outcome:
        'Laura līdz nākamajai tikšanās reizei sagatavos vienkāršu starpkomandu pieprasījumu pieņemšanas veidnes melnrakstu.',
      checkpoint: {
        text: 'Sarunas ar platformas komandu ir apstājušās — joprojām gaidām viņu prioritizāciju.',
        tag: 'at_risk',
      },
    },
    cycle2: {
      employee: {
        moodNow: 'good',
        moodTrend: 'better',
        moodText:
          'Šajā periodā izlaidu rēķinu eksporta migrāciju, kas jau ilgu laiku bija pār mani karājusies — jūtos daudz vieglāk, kad tā beidzot ir pabeigta.',
        feelings: ['motivated', 'confident'],
        feelingsText:
          'Jaunā darbinieka ievadīšana norisinājās labi — labs atgādinājums, ka man tiešām patīk mentoringa puse.',
        workloadNow: 'just_right',
        workloadTrend: 'less',
        workloadText:
          'Slodze mazliet samazinājusies, jo migrācija ir pabeigta. Labs brīdis uzņemties ko jaunu, ja atradīsies piemērots uzdevums.',
        growth: [
          'Sapratu, ka īsi ierakstīti demonstrējumi piesaista daudz vairāk uzmanības nekā rakstiski koda pārskata komentāri — pāreju uz šo formātu sarežģītākiem pārskatiem.',
          'Pirmo reizi piedalījos incidenta izvērtēšanā — noderīgi bija redzēt, kā komanda izseko cēloni līdz saknei.',
        ],
        harder:
          'Starpkomandu servisu dokumentācija ir izkaisīta pa piecām dažādām wiki lapām — vienota avota joprojām nav, kaut arī esmu to jau vairākas reizes minējusi.',
        achievements: [
          'Pilnībā izlaidu rēķinu eksporta migrāciju, ātrāk par sākotnējo aplēsi.',
          'Palīdzēju jaunajam darbiniekam veiksmīgi izturēt pirmo dežūru bez neviena eskalācijas gadījuma.',
        ],
        whatElse: [
          'Interesē nākamajā ceturksnī vadīt starpkomandu projektu — vēlos parunāt, kā tas varētu izskatīties.',
        ],
      },
      manager: {
        howWasPeriod:
          'Spēcīgs periods — Laura uzņēmās rēķinu eksporta migrāciju no plānošanas līdz ieviešanai, un tā noritēja gludi. Arī pati, bez atgādinājuma, iesaistījās mentoringā.',
        feedback:
          'Atbildība un lietu novešana līdz galam ir izcila — man nav jāraizējas par migrācijas uzdevumiem, ja tie uzticēti Laurai. Gribētu redzēt vairāk proaktīvu atjauninājumu ikdienas sanāksmēs, nevis gaidīt, kamēr par tiem jautā — pats darbs reti ir problēma, jautājums ir par redzamību.',
        howCanIHelp:
          'Runāju ar platformas komandas vadītāju par starpkomandu pieprasījumu pieņemšanas procesu — viņi ir atvērti kopīgai veidnei, tiklīdz būs tavs melnraksts. Palīdzēšu to izplatīt, kad tā būs gatava.',
        achievements: [
          'Uzņēmās rēķinu eksporta migrāciju no plānošanas līdz izlaišanai bez neviena atgriešanās gadījuma.',
          'Pirmo reizi bija mentors jaunajam darbiniekam dežūras laikā — gluda ievadīšana, bez incidentiem.',
        ],
        whatElse: [
          'Gatavs pārrunāt, kā nākamajā ceturksnī praktiski izskatītos Lauras vadīts starpkomandu projekts.',
        ],
      },
      comment: 'Apsveicu, ka izlaidi to pirms termiņa!',
      outcomeNew:
        'Kārlis dalīsies ar gala pieprasījumu pieņemšanas veidni platformas komandai, tiklīdz Lauras melnraksts būs gatavs.',
      checkpoint: {
        text: 'Sākām sarunas ar platformas komandu.',
        tag: 'on_track',
      },
    },
  },

  es: {
    employeeEmail: 'demo-employee-es@example.com',
    employeeName: 'Lucía Fernández',
    managerEmail: 'demo-manager-es@example.com',
    managerName: 'Carlos Ramírez',
    goal: {
      title: 'Liderar un proyecto entre equipos de principio a fin',
      description:
        'Encargarse de la planificación y entrega de un proyecto que abarque al menos dos equipos.',
    },
    cycle1: {
      employee: {
        moodNow: 'neutral',
        moodTrend: 'worse',
        moodText:
          'Este proyecto se ha alargado más de lo previsto, y siento la presión de una fecha límite ajustada con muchas incógnitas todavía sin resolver.',
        feelings: ['overwhelmed', 'anxious'],
        feelingsText:
          'Estoy compaginando el trabajo de migración con muchas solicitudes puntuales de otros equipos — cuesta encontrar tiempo real para concentrarme.',
        workloadNow: 'too_much',
        workloadTrend: 'more',
        workloadText:
          'La migración de exportación de facturación resultó ser mucho más grande de lo que se había estimado. Me vendría bien ayuda para decidir qué puede esperar.',
        growth: [
          'Aprendí lo enredadas que están las dependencias de la exportación de facturación — un buen ejemplo de por qué la documentación de linaje de datos realmente importa.',
          'Trabajé en pareja con un ingeniero senior en un caso límite complicado de la migración y aprendí nuevas técnicas de depuración.',
        ],
        harder:
          'Las solicitudes entre equipos todavía requieren muchas idas y vueltas por Slack antes de que alguien se comprometa con un plazo. Un proceso compartido de recepción de solicitudes ahorraría mucho tiempo persiguiendo respuestas.',
        achievements: [
          'Definí el alcance completo de la migración de exportación de facturación, incluyendo varios casos límite que nadie había señalado antes.',
        ],
        whatElse: [
          'Quiero hablar de un plazo realista para la migración ahora que sabemos lo grande que resultó ser en realidad.',
        ],
      },
      manager: {
        howWasPeriod:
          'Un periodo difícil — la migración de exportación de facturación resultó ser más compleja de lo que nadie esperaba, y Sofía ha estado cargando mucho peso solo para entender el alcance real.',
        feedback:
          'La minuciosidad con la que se definió el alcance ha sido realmente valiosa, aunque haya supuesto un comienzo más lento de lo que nos gustaría. Me gustaría que replanteáramos el plazo juntos, en lugar de que cargues sola con esa presión.',
        howCanIHelp:
          'Voy a filtrar algunas de las solicitudes puntuales que llegan de otros equipos para que tengas más espacio para concentrarte de verdad.',
        achievements: [
          'Hizo el trabajo difícil y poco vistoso de desenredar una migración que estaba mal dimensionada desde el principio — mucho mejor descubrirlo ahora que a mitad del despliegue.',
        ],
        whatElse: [
          'Quiero alinear un nuevo plazo realista y ver si necesitamos otro par de manos.',
        ],
      },
      comment:
        'Gracias por dejarlo tan claro — resolvamos el plazo juntos, no tienes que cargar con esto sola.',
      outcome:
        'Sofía redactará un borrador de plantilla sencilla para la recepción de solicitudes entre equipos antes de la próxima reunión.',
      checkpoint: {
        text: 'Las conversaciones con el equipo de plataforma se han estancado — seguimos esperando su priorización.',
        tag: 'at_risk',
      },
    },
    cycle2: {
      employee: {
        moodNow: 'good',
        moodTrend: 'better',
        moodText:
          'Este periodo lancé la migración de exportación de facturación, que llevaba tiempo pendiente — me siento mucho más ligera ahora que ya está hecha.',
        feelings: ['motivated', 'confident'],
        feelingsText:
          'La incorporación del nuevo empleado fue bien — un buen recordatorio de que de verdad disfruto la parte de mentoría.',
        workloadNow: 'just_right',
        workloadTrend: 'less',
        workloadText:
          'La carga de trabajo bajó un poco ahora que la migración está terminada. Buen momento para asumir algo nuevo si encaja.',
        growth: [
          'Aprendí que los recorridos grabados y breves generan mucha más participación que los comentarios escritos en las revisiones de código — voy a usar ese formato para las revisiones más complicadas.',
          'Participé por primera vez en un post-mortem — fue útil ver cómo el equipo rastrea un incidente hasta su causa raíz.',
        ],
        harder:
          'La documentación de los servicios entre equipos está repartida en cinco wikis distintas — todavía no hay una única fuente de verdad, aunque ya lo he planteado varias veces.',
        achievements: [
          'Lancé la migración de exportación de facturación de principio a fin, antes de la estimación original.',
          'Guie al nuevo empleado durante su primer turno de guardia sin una sola escalación.',
        ],
        whatElse: [
          'Me interesa liderar un proyecto entre equipos el próximo trimestre — quiero hablar de cómo podría ser ese camino.',
        ],
      },
      manager: {
        howWasPeriod:
          'Un periodo sólido — Sofía se hizo cargo de la migración de exportación de facturación desde el diseño hasta el despliegue, y salió limpia. También se involucró en la mentoría sin que se lo pidieran.',
        feedback:
          'La responsabilidad y el seguimiento son excelentes — no me preocupo por el trabajo de migración una vez que se lo asigno a Sofía. Me encantaría ver más actualizaciones proactivas en el standup en lugar de esperar a que se pregunte; el trabajo en sí casi nunca es el problema, es la visibilidad.',
        howCanIHelp:
          'Planteé la idea del proceso de recepción de solicitudes entre equipos al responsable del equipo de plataforma — están abiertos a una plantilla compartida en cuanto tengas un borrador. Ayudaré a difundirla cuando esté lista.',
        achievements: [
          'Se hizo cargo de la migración de exportación de facturación desde el diseño hasta el lanzamiento sin una sola reversión.',
          'Fue mentora por primera vez del turno de guardia de un nuevo empleado — un arranque fluido, sin incidentes.',
        ],
        whatElse: [
          'Listo para hablar de cómo sería en la práctica que Sofía liderara un proyecto entre equipos el próximo trimestre.',
        ],
      },
      comment: '¡Felicidades por lanzarlo antes de lo previsto!',
      outcomeNew:
        'Diego compartirá la plantilla final de recepción de solicitudes con el equipo de plataforma en cuanto el borrador de Sofía esté listo.',
      checkpoint: {
        text: 'Iniciamos las conversaciones con el equipo de plataforma.',
        tag: 'on_track',
      },
    },
  },
};
