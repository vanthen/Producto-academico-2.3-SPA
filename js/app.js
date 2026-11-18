/**
 * ENDPOINT ÚNICO
 * Todas las peticiones al backend pasan por este mismo archivo,
 * diferenciadas por el parámetro "action."
 */
const API_ENDPOINT = './api/index.php';

/**
 * ESTADO GLOBAL
 */
const state = {
	cardData: {
		name: '',
		bio:'',
		avatarUrl: 'src/assets/Avatares0.jpg', //Avatar por defecto
		color: '#3b82f6'
	}
}; /*Todos los valores originales de la página-----AKA-----constantes*/

/**
 * REFERENCIAS AL DOM
*/
const dom = { /*Constantes del dom para ligar las instancias del HTML con los elemenos del JS */
	inputName: document.getElementById('input-name'), /*tienen q ser EXACTAMENTE iguales a los ids del index*/
	inputBio: document.getElementById('input-bio'),
	inputColor: document.getElementById('input-color'), /*Elementos HTML a elementos en JavaScript*/
	form: document.getElementById('profile-form'),
	avatarSelector: document.getElementById('avatar-selector'),
	previewName: document.getElementById('name-preview'),
	previewBio: document.getElementById('bio-preview'),
	previewAvatar: document.getElementById('avatar-preview'),
	previewHeader: document.getElementById('preview-header'),
	viewCreate: document.getElementById('view-create'),
	inputSearch: document.getElementById('github-search'), /* Para el API de GitHub */
	btnFetch: document.getElementById('btn-fetch'),
	apiStatus: document.getElementById('api-status'),
	viewGallery: document.getElementById('view-gallery'), /* Para la galeria */
	galleryContainer: document.getElementById('gallery-container'),
	btnReloadGallery: document.getElementById('btn-reload-gallery'),
	navLinks : document.querySelectorAll('.nav-link'),

	navCrear: document.getElementById('nav-crear'),
	navGaleria: document.getElementById('nav-galeria'),
	navLogout: document.getElementById('nav-logout'),
	navLogin: document.querySelector('a[href="#/login"]'),

	viewAuth: document.getElementById('view-auth'),
	tabLogin: document.getElementById('tab-login'),
	tabRegister: document.getElementById('tab-register'),
	formLogin: document.getElementById('form-login'),
	formRegistro: document.getElementById('form-registro'),
  	authStatus: document.getElementById('auth-status'),
};

/**
 * Llamada al endpoint único. La cookie de sesión PHP viaja sola
 * (credentials: 'same-origin') y mantiene la sesión entre recargas.
 * Devuelve el JSON; si la respuesta no es exitosa lanza un Error.
 */
const callApi = async (action, { method = 'GET', body = null } = {}) => {
	const response = await fetch(`${API_ENDPOINT}?action=${action}&t=${Date.now()}`, {
		method,
		body,
		credentials: 'same-origin'
	});
	let result;
	try {
		result = await response.json();
	} catch {
		throw new Error('Respuesta inválida del servidor.');
	}
	if (!response.ok || !result.success) {
		const err = new Error(result.error || 'El servidor rechazó la petición.');
		err.status = response.status;
		throw err;
	}
	return result;
};

const escapeHtml = (str) => String(str ?? '').replace(/[&<>"']/g, c => (
	{ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
));

/**
 * SESIÓN
 */
const session = { user: null };

const showAuthMsg = (text, type) => {
	dom.authStatus.textContent = text;
	dom.authStatus.className = `status-msg status-${type}`;
};

const switchAuthTab = (tab) => {
	const isLogin = tab === 'login';
	dom.tabLogin.classList.toggle('active', isLogin);
	dom.tabRegister.classList.toggle('active', !isLogin);
	dom.formLogin.classList.toggle('active', isLogin);
	dom.formRegistro.classList.toggle('active', !isLogin);
	dom.authStatus.textContent = '';
};

const updateNav = () => {
	const logged = !!session.user;
	dom.navLogin.style.display = logged ? 'none' : '';
	dom.navCrear.style.display = logged ? '' : 'none';
	dom.navGaleria.style.display = logged ? '' : 'none';
	dom.navLogout.style.display = logged ? '' : 'none';
};

const refreshSession = async () => {
	try {
		const result = await callApi('check_session');
		session.user = result.data;
	} catch {
		session.user = null;
	}
	updateNav();
};

/**
 * EVENTOS SWITCH PESTAÑAS (nueva función)
 */
const setupAuthEvents = () => {
	dom.tabLogin.addEventListener('click', () => switchAuthTab('login'));
	dom.tabRegister.addEventListener('click', () => switchAuthTab('register'));
};

/**
 * GUARDAR USUARIO (registro en MySQL)
 */
const setupRegistroEvents = () => {
	dom.formRegistro.addEventListener('submit', async (e) => {
		e.preventDefault();
		const btnSubmit = dom.formRegistro.querySelector('button[type="submit"]');
		const textoOriginal = btnSubmit.textContent;
		btnSubmit.textContent = 'Registrando...';
		btnSubmit.disabled = true;

		try {
			const result = await callApi('registrar_usuario', {
				method: 'POST',
				body: new FormData(dom.formRegistro)
			});
			const email = document.getElementById('email').value;
			dom.formRegistro.reset();
			switchAuthTab('login');
			document.getElementById('login-email').value = email;
			showAuthMsg(result.message, 'success');
		} catch (error) {
			showAuthMsg(error.message, 'error');
		} finally {
			btnSubmit.textContent = textoOriginal;
			btnSubmit.disabled = false;
		}
	});
}

const setupLoginEvents = () => {
	dom.formLogin.addEventListener('submit', async (e) => {
		e.preventDefault();

		const btnSubmit = dom.formLogin.querySelector('button[type="submit"]');
		const textoOriginal = btnSubmit.textContent;
		btnSubmit.textContent = 'Verificando...';
		btnSubmit.disabled = true;

		try {
			const result = await callApi('login', {
				method: 'POST',
				body: new FormData(dom.formLogin)
			});

			session.user = result.data;
			dom.formLogin.reset();
			dom.authStatus.textContent = '';
			updateNav();

			// Reiniciamos la tarjeta en edición para no heredar datos de otra sesión
			state.cardData = { name: '', bio: '', avatarUrl: 'src/assets/Avatares0.jpg', color: '#3b82f6' };
			renderCard();

			window.location.hash = '#/crear';
			handleRouting();
		} catch (error) {
			showAuthMsg(error.message, 'error');
		} finally {
			btnSubmit.textContent = textoOriginal;
			btnSubmit.disabled = false;
		}
	});

	// Cerrar sesión
	dom.navLogout.addEventListener('click', async (e) => {
		e.preventDefault();
		try {
			await callApi('logout');
		} catch (error) {
			console.error('Error cerrando sesión: ', error);
		}
		session.user = null;
		dom.galleryContainer.innerHTML = '';
        resetCardState();
		updateNav();
		switchAuthTab('login');
		window.location.hash = '#/login';
		handleRouting();
	});
};

/**
 *Generador del selector de avatares (crea los 12 elementos <img> dinanimcamente)
 */
const initAvatarSelector = () => {
	const totalAvatares = 12; //Del 0 al 11

	for (let i = 0; i < totalAvatares; i++) {
		const rutaImagen = `src/assets/Avatares${i}.jpg`;
		const img = document.createElement('img');
		img.src = rutaImagen;
		img.alt = `Avatar ${i}`; 
		img.className = 'avatar-option';
		//Guardamos la ruta en un atributo de datos para leerlo facilmente
		img.dataset.url = rutaImagen;

		//Evento: Al hacer clic en una miniatura, actualizamos el estado global
		img.addEventListener('click', () => {
			state.cardData.avatarUrl = rutaImagen;
			renderCard(); //Forzamos la actualización de la UI
		});

		dom.avatarSelector.appendChild(img);
	}
};

/**
 * FUNCION API GITHUB
 */

const fetchGitHubData = async (username) => {
	if (!username) return;
	dom.apiStatus.textContent = 'Buscando usuario en GitHub...';
	dom.apiStatus.className = 'status-msg status-loading';
	dom.btnFetch.disabled = true;
	try {
		const response = await fetch(`https://api.github.com/users/${username}`);
		if (!response.ok) throw new Error("Usuario no encontrado")
			const data = await response.json();

		state.cardData.name = data.name || data.login;
		state.cardData.bio = data.bio || 'Este usuario no tiene biografia publica.';
		state.cardData.avatarUrl = data.avatar_url;

		renderCard();

		dom.apiStatus.textContent = '¡Datos cargados correctamente :D!'
		dom.apiStatus.className = 'status-msg status-success'
	
	} catch (error) {
		dom.apiStatus.textContent = error.message;
		dom.apiStatus.className = 'status-msg status-error'
	} finally {
		dom.btnFetch.disabled = false;
		setTimeout(() => dom.apiStatus.textContent = '', 3000);
	}

} 

/**
 * FUNCION DE RENDERIZADO 
 */
const renderCard = () => {
	dom.previewName.textContent = state.cardData.name || 'Nombre Apellido';
	dom.previewBio.textContent = state.cardData.bio || 'La biografia aparecera aqui...';
	dom.previewAvatar.src = state.cardData.avatarUrl;
	dom.previewHeader.style.backgroundColor = state.cardData.color;
	dom.inputName.value = state.cardData.name;
	dom.inputBio.value = state.cardData.bio;
	dom.inputColor.value = state.cardData.color;
	//Actualizar visualmente qué avatar está seleccionado en la cuadricula
	const avatares = dom.avatarSelector.querySelectorAll('.avatar-option');
	avatares.forEach(img => {
		if (img.dataset.url === state.cardData.avatarUrl) {
			img.classList.add('selected');
		} else {
			img.classList.remove('selected');
		}
	});
}

/**
 * LIMPIAR FORMULARIO Y LA TARJETA
 * Restablece los valores a su estado por defecto
 */
const resetCardState = () => {
	//1- Restablecemos el estado global
	state.cardData = {
    	name: '',
        bio: '',
        avatarUrl: 'src/assets/Avatares0.jpg',
      	color: '#3b82f6'
    };
  
  //2- Limpiamos el campo de busqueda de GitHub si se uso
  dom.inputSearch.value = '';
  dom.apiStatus.textContent = '';
  dom.apiStatus.className = 'status-msg';
  
  //3- Forzamos el renderizado para que la vista previa y la los inputs se limpien
  renderCard();
}

/**
 * EVENTOS MANUALES
 */
const setupManualEvents = () => {
	dom.inputName.addEventListener('input', (e) => {
		state.cardData.name = e.target.value;
		renderCard();
	});

	dom.inputBio.addEventListener('input', (e) => {
		state.cardData.bio = e.target.value;
		renderCard();
	});

	dom.inputColor.addEventListener('input', (e) => {
		state.cardData.color = e.target.value;
		renderCard();
	});
	dom.form.addEventListener('submit', (e) => {
		e.preventDefault();
		saveCardToServer();
	});
}

/**
 * GUARDAR TARJETA (vía endpoint único, action=crear_tarjeta)
 */
const saveCardToServer = async () => {
	if (!state.cardData.name.trim()) { //Valida que el nombre de la tarjeta no este vacío
		alert('Por favor, ingresa un nombre para la tarjeta.');
		return;
	}

	const btnSubmit = dom.form.querySelector('button[type="submit"]');
	const textoOriginal = btnSubmit.textContent;
	btnSubmit.textContent = 'Guardando en servidor...';
	btnSubmit.disabled = true;
	
	try {
		const formData = new FormData();
		formData.append('datos_tarjeta', JSON.stringify(state.cardData));

		const result = await callApi('crear_tarjeta', { method: 'POST', body: formData });
		alert(result.message);

	} catch (error) {
		if (error.status === 401) { // La sesión expiró
			session.user = null;
			updateNav();
			handleRouting();
		}
		alert('Error: ' + error.message);
		console.error('Error capturado en el catch:', error);
	} finally { //Sí o sí sucede, aun si no se completó lo del try (que se vaya al catch)
		resetCardState(); //Limpiamos la tarjeta y el formulario
		btnSubmit.textContent = textoOriginal;
		btnSubmit.disabled = false;
	}
};

/**
 *  LOGICA DE LA GALERIA (Consumo y renderizado)
 */
const loadGalleryData = async () => {
	dom.galleryContainer.innerHTML = '<p class="status-msg status-loading">Cargando tarjetas...</p>'

	try {
		let result;
		try {
			result = await callApi('listar_tarjetas');
		} catch (err) {
			if (err.status === 401) { // La sesión expiró
				session.user = null;
				updateNav();
				window.location.hash = '#/login';
				handleRouting();
				return;
			}
			throw err;
		}

		const tarjetas = result.data;
		if (!Array.isArray(tarjetas)) {
			throw new Error('El servidor devolvió las tarjetas en un formato inválido.');
		}

		if (tarjetas.length === 0) {
			throw new Error('La galeria esta vacia')
		}

		dom.galleryContainer.innerHTML = '';

		tarjetas.reverse().forEach(tarjeta => {
			const cardHTML = `
			<article class="card">
				<div class="card-header" style="background-color: ${escapeHtml(tarjeta.color)}"></div>
				<img src="${escapeHtml(tarjeta.avatarUrl)}" alt="Avatar de ${escapeHtml(tarjeta.name)}" class="avatar">
				<div class="card-body">
					<h3>${escapeHtml(tarjeta.name)}</h3>
					<p style="font-size: 0.95rem; color: #64748b;">${escapeHtml(tarjeta.bio)}</p>
				</div>
			</article>
			`;
			dom.galleryContainer.insertAdjacentHTML(`beforeend`, cardHTML);
		});

	} catch (error) {
		dom.galleryContainer.innerHTML = `<p class="status-msg status-error">${error.message}</p>`;
	}
}

/**
 * ENRUTADOR BASADO EN HASH (el corazión de la SPA)
 */
const handleRouting = () => {
	// Sin sesión solo se puede ver el login/registro; con sesión, nunca el login.
	let currentHash = window.location.hash;
	if (!session.user) {
		currentHash = '#/login';
	} else if (currentHash !== '#/galeria') {
		currentHash = '#/crear';
	}
	if (window.location.hash !== currentHash) {
		history.replaceState(null, '', currentHash);
	}

	dom.navLinks.forEach(link => {
		link.classList.toggle('active', link.getAttribute('href') === currentHash);
	});

	// Ocultamos todas las vistas y mostramos solo la que corresponde
	[dom.viewAuth, dom.viewCreate, dom.viewGallery].forEach(v => {
		v.classList.remove('active');
		v.style.display = 'none';
	});

	const show = (view) => {
		view.style.display = 'block';
		setTimeout(() => view.classList.add('active'), 10);
	};

	if (currentHash === '#/login') {
		show(dom.viewAuth);
	} else if (currentHash === '#/galeria') {
		show(dom.viewGallery);
		loadGalleryData();
	} else {
		show(dom.viewCreate);
	}
};

/**
 * Inicialización de la aplicación
 */
document.addEventListener('DOMContentLoaded', async () => {
	initAvatarSelector(); //Inicializamos la cuadricula de imagenes
	setupManualEvents();
	setupAuthEvents();
	setupRegistroEvents();
	setupLoginEvents();

	dom.btnFetch.addEventListener('click', () => {
		const username = dom.inputSearch.value.trim();
		fetchGitHubData(username);
	});

	dom.inputSearch.addEventListener('keypress', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault();
			dom.btnFetch.click();
		} 
	});

	renderCard();
	dom.btnReloadGallery.addEventListener('click', loadGalleryData);
	window.addEventListener('hashchange', handleRouting);

	// Pregunta al servidor si la sesión sigue activa (persiste tras recargar la página)
	await refreshSession();
	handleRouting();
});

