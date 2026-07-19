// Base de datos de las 26 escenas (Historia Acción Honduras)
const scenes = [
    {
        id: 1,
        img: "adalid.png",
        title: "La Roca de las Metas",
        caption: "Adalid escala la inmensa 'Roca de las Metas 2025' y descubre, tallada en la cima, la frase: 'La misión solo se completa en equipo'."
    },
    {
        id: 2,
        img: "alex_alvarado.png",
        title: "El Burrito Navideño",
        caption: "Alex Alvarado llega triunfal en un burrito sobrecargado de canastas y juguetes, seguido por una multitud de niños felices."
    },
    {
        id: 3,
        img: "alex_castillo.png",
        title: "El Guardián del Tráfico",
        caption: "Alex Castillo actúa como semáforo humano improvisado en un cruce rural caótico con motos, gallinas, perros y camiones."
    },
    {
        id: 4,
        img: "beyquer.png",
        title: "El Rayo Futurista",
        caption: "Beyquer conduce su Cybertruck futurista llamado 'RAYO' por una aldea, saludado con asombro por niños y gallinas."
    },
    {
        id: 5,
        img: "carlos.png",
        title: "Misión: Documentos Seguros",
        caption: "Carlos corre por un callejón peligroso protegiendo documentos vitales mientras hombres armados lo persiguen. ¡Los archivos están a salvo!"
    },
    {
        id: 6,
        img: "david.png",
        title: "Fe y Juventud",
        caption: "David predica con energía y anima a un grupo de jóvenes dentro de una pequeña capilla rural."
    },
    {
        id: 7,
        img: "eduardo.png",
        title: "El Escultor Financiero",
        caption: "Eduardo, con seriedad absoluta, talla las palabras 'REGLAMENTO FINANCIERO' en un enorme bloque de piedra."
    },
    {
        id: 8,
        img: "edwing.png",
        title: "La Voz en la ONU",
        caption: "Edwing toma el podio de las Naciones Unidas, hablando apasionadamente sobre las comunidades rurales y la niñez."
    },
    {
        id: 9,
        img: "francisca.png",
        title: "El Mapa Infinito",
        caption: "Francisca desenrolla un mapa extremadamente largo (el POA) en un camino polvoriento, trazando cada aldea y cada meta."
    },
    {
        id: 10,
        img: "galileo.png",
        title: "El Profeta Digital",
        caption: "Galileo carga una enorme TV por la aldea mientras los niños lo siguen, emocionados por el aprendizaje digital."
    },
    {
        id: 11,
        img: "orlando.png",
        title: "Minería Educativa",
        caption: "Orlando, vestido de minero, señala una veta de oro en un túnel, simbolizando los recursos encontrados para la educación."
    },
    {
        id: 12,
        img: "goel.png",
        title: "Capacitación Nivel Dios",
        caption: "Goel imparte una clase técnica en un aula caótica llena de fórmulas, errores de sistema y laptops humeantes."
    },
    {
        id: 13,
        img: "jennifer.png",
        title: "Exploradora de la Selva",
        caption: "Jennifer, como una exploradora intrépida con monos asistentes, busca escuelas olvidadas y familias en la selva."
    },
    {
        id: 14,
        img: "jhony.png",
        title: "Moto-Carga Extrema",
        caption: "Jhony conduce con pericia una moto peligrosamente sobrecargada de canastas de regalo por un camino de tierra."
    },
    {
        id: 15,
        img: "joaquin.png",
        title: "La Condecoración del General",
        caption: "Don Joaquín, en su rol de exmilitar, es 'condecorado' por el General de atrás con una pistola de agua neón en tono de broma."
    },
    {
        id: 16,
        img: "milixa.png",
        title: "Ruta 4x4",
        caption: "Milixa conduce una cuatrimoto a través de lodo profundo, decidida a llegar a las familias más remotas."
    },
    {
        id: 17,
        img: "nancy.png",
        title: "Rodeo Porcino",
        caption: "Nancy monta un gran cerdo a través de un campo lodoso como si fuera una escena de rodeo, sonriendo todo el camino."
    },
    {
        id: 18,
        img: "neptaly.png",
        title: "Combustible de Campo",
        caption: "Neptaly disfruta felizmente de un plato humeante de sopa de gallina india, su combustible esencial para la misión."
    },
    {
        id: 19,
        img: "nubia.png",
        title: "El Censo Animal",
        caption: "Nubia levanta un censo rural con un gallo en su cabeza, mientras una cabra con gafas de sol y un cerdo la observan."
    },
    {
        id: 20,
        img: "sabrina.png",
        title: "Hipnosis Administrativa",
        caption: "Sabrina hipnotiza a un grupo de jóvenes con una espiral brillante para que 'amen los reportes y lleguen a tiempo'."
    },
    {
        id: 21,
        img: "suyapa.png",
        title: "Correo Aéreo Express",
        caption: "Suyapa corre por la pista de aterrizaje con un sobre urgente, intentando entregarlo antes de que despegue el avión de carga."
    },
    {
        id: 22,
        img: "william.png",
        title: "El Escriba del Inventario",
        caption: "William escribe 'Inventario Acción Honduras 1502–2025' en un pergamino infinito usando una pluma antigua."
    },
    {
        id: 23,
        img: "yimi.png",
        title: "El Arca de Yimi",
        caption: "Yimi viaja con una granja entera de animales apilados sobre él, remolcado por un cocodrilo con sombrero vaquero."
    },
    {
        id: 24,
        img: "zamir.png",
        title: "La Torre de Cartón",
        caption: "Zamir camina por la calle polvorienta manteniendo el equilibrio de una torre ridículamente alta de cajas de cartón."
    },
    {
        id: 25,
        img: "patricia.png",
        title: "La Contadora Implacable",
        caption: "Patricia usa un ábaco antiguo rodeada de montañas de papel, decidida a que todos los números cuadren perfectamente."
    },
    {
        id: 26,
        img: "elyn.png",
        title: "Caos Organizado",
        caption: "Escena final: Todo el equipo reunido simbólicamente, comprendiendo que su caos épico y cómico es lo que hace funcionar a Acción Honduras."
    }
];

// LÓGICA
let currentIndex = 0;
const totalScenes = scenes.length;

// Elementos del DOM
const imageStage = document.getElementById('image-stage');
const titleEl = document.getElementById('scene-title');
const captionEl = document.getElementById('scene-caption');
const counterEl = document.getElementById('scene-counter');
const timeline = document.getElementById('timeline');

// Renderizar Escena
function renderScene(index) {
    if (index < 0) index = 0;
    if (index >= totalScenes) index = totalScenes - 1;
    currentIndex = index;
    const scene = scenes[currentIndex];

    // Animación de entrada
    imageStage.style.opacity = 0;
    
    setTimeout(() => {
        // Cargar datos
        imageStage.style.backgroundImage = `url('img/${scene.img}')`;
        titleEl.innerText = scene.title;
        captionEl.innerText = scene.caption;
        counterEl.innerText = `ESCENA ${String(scene.id).padStart(2, '0')} / ${totalScenes}`;
        
        // Mover barra
        timeline.value = currentIndex;
        
        // Mostrar
        imageStage.style.opacity = 1;
    }, 200);
}

// Navegación
function nextScene() {
    if (currentIndex < totalScenes - 1) renderScene(currentIndex + 1);
}

function prevScene() {
    if (currentIndex > 0) renderScene(currentIndex - 1);
}

function jumpToScene(val) {
    renderScene(parseInt(val));
}

// Teclado
document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight') nextScene();
    if (e.key === 'ArrowLeft') prevScene();
});

// Inicializar
window.onload = () => {
    renderScene(0);
};