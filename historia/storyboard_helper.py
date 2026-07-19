# Script de ayuda para pre-producción del Storyboard de Acción Honduras
# No requiere librerías externas. Solo ejecuta: python storyboard_helper.py

scenes = [
    {"id": 1, "char": "Adalid", "desc": "Escalando la Roca de las Metas 2025."},
    {"id": 2, "char": "Alex Alvarado", "desc": "Llegando en burro con regalos y niños."},
    {"id": 3, "char": "Alex Castillo", "desc": "Guardián de tráfico en cruce rural caótico."},
    {"id": 4, "char": "Beyquer", "desc": "En Cybertruck 'RAYO' saludando a la aldea."},
    {"id": 5, "char": "Carlos", "desc": "Corriendo en callejón salvando documentos."},
    {"id": 6, "char": "David", "desc": "Predicando a jóvenes en capilla."},
    {"id": 7, "char": "Eduardo", "desc": "Tallando 'REGLAMENTO FINANCIERO' en piedra."},
    {"id": 8, "char": "Edwing", "desc": "Hablando en la ONU sobre niñez rural."},
    {"id": 9, "char": "Francisca", "desc": "Desenrollando el mapa infinito del POA."},
    {"id": 10, "char": "Galileo", "desc": "Cargando TV gigante seguido por niños."},
    {"id": 11, "char": "Orlando", "desc": "Minero señalando veta de oro (recursos)."},
    {"id": 12, "char": "Goel", "desc": "Capacitación técnica en aula caótica."},
    {"id": 13, "char": "Jennifer", "desc": "Exploradora en selva con monos asistentes."},
    {"id": 14, "char": "Jhony", "desc": "En moto sobrecargada de canastas."},
    {"id": 15, "char": "Joaquín", "desc": "Ex-militar condecorado con pistola de agua."},
    {"id": 16, "char": "Milixa", "desc": "En cuatrimoto por lodo profundo."},
    {"id": 17, "char": "Nancy", "desc": "Montando un cerdo en rodeo lodoso."},
    {"id": 18, "char": "Neptaly", "desc": "Comiendo sopa de gallina india."},
    {"id": 19, "char": "Nubia", "desc": "Censo rural con animales observando."},
    {"id": 20, "char": "Sabrina", "desc": "Hipnotizando jóvenes con espiral."},
    {"id": 21, "char": "Suyapa", "desc": "Corriendo en pista hacia avión de carga."},
    {"id": 22, "char": "William", "desc": "Escribiendo Inventario con pluma antigua."},
    {"id": 23, "char": "Yimi", "desc": "Con granja de animales y cocodrilo."},
    {"id": 24, "char": "Zamir", "desc": "Cargando torre de cajas de cartón."},
    {"id": 25, "char": "Patricia", "desc": "Con ábaco y montañas de papeles."},
    {"id": 26, "char": "Elyn", "desc": "Reunión simbólica del caos organizado."}
]

def print_storyboard():
    print("\n" + "="*60)
    print("      ACCIÓN HONDURAS: STORYBOARD ÉPICO (2025)      ")
    print("="*60 + "\n")
    
    for scene in scenes:
        print(f"ESCENA {scene['id']:02d}: {scene['char'].upper()}")
        print(f"ACCIÓN: {scene['desc']}")
        print("-" * 40)

if __name__ == "__main__":
    print_storyboard()