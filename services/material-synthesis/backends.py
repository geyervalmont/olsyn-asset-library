"""Pinned, offline photo-to-material backends. GPU imports are lazy."""
import os
import numpy as np
from image_ops import normalize_normals


def estimate(image, models, backend, seed, progress=None):
    import torch
    if backend == 'chord':
        from omegaconf import OmegaConf
        from chord import ChordModel
        from safetensors.torch import load_file
        config = OmegaConf.load('/opt/chord/config/chord.yaml')
        config.model.stable_diffusion.hf_key = str(models / 'base')
        model = ChordModel(config)
        model.load_state_dict(load_file(str(models / 'chord_v1.safetensors')))
        model.eval().to('cuda')
        tensor = torch.from_numpy(np.asarray(image).copy()).permute(2, 0, 1).float().div(255).unsqueeze(0).to('cuda')
        with torch.inference_mode(), torch.autocast(device_type='cuda', dtype=torch.bfloat16):
            outputs = model(tensor)
        def array(value):
            value = value.detach().float().cpu().numpy()[0]
            return value.transpose(1, 2, 0) if value.ndim == 3 else value
        normal = array(outputs['normal'])
        # CHORD's renderer uses image-row-down Y; canonical maps use +Y up.
        normal[..., 1] = 1 - normal[..., 1]
        normal = normalize_normals(normal)
        return {'base_color': array(outputs['basecolor']), 'normal': normal,
                'roughness': array(outputs['roughness']), 'metallic': array(outputs['metalness'])}, {
                    'model': 'chord', 'model_revision': os.environ['CHORD_REVISION']}
    if backend != 'rgbx':
        raise ValueError('Unsupported material backend.')
    from diffusers import DDIMScheduler
    from pipeline_rgb2x import StableDiffusionAOVMatEstPipeline
    pipeline = StableDiffusionAOVMatEstPipeline.from_pretrained(
        str(models / 'rgbx'), local_files_only=True, torch_dtype=torch.float16).to('cuda')
    pipeline.scheduler = DDIMScheduler.from_config(
        pipeline.scheduler.config, rescale_betas_zero_snr=True, timestep_spacing='trailing')
    pipeline.set_progress_bar_config(disable=True)
    # Match the authors' LDR loader: the conditioning photo is linear RGB.
    photo = torch.from_numpy(np.asarray(image).copy()).float().div(255).pow(2.2).permute(2, 0, 1).to('cuda')
    generator = torch.Generator(device='cuda').manual_seed(seed)
    maps = {}
    channels = [('base_color', 'albedo', 'Albedo (diffuse basecolor)'),
                ('normal', 'normal', 'Camera-space Normal'),
                ('roughness', 'roughness', 'Roughness'),
                ('metallic', 'metallic', 'Metallicness')]
    with torch.inference_mode():
        for index, (role, aov, prompt) in enumerate(channels):
            if progress:
                progress('estimating_material', completed=index, total=len(channels), role=role)
            value = pipeline(prompt=prompt, photo=photo, num_inference_steps=50,
                             height=image.height, width=image.width, generator=generator,
                             required_aovs=[aov], output_type='np').images[0][0]
            if not np.isfinite(value).all():
                raise ValueError('Non-finite material prediction.')
            # RGB-X already gamma-encodes albedo, and leaves data maps linear.
            maps[role] = value
            if progress:
                progress('estimating_material', completed=index + 1, total=len(channels), role=role)
    # RGB-X uses camera +X right, +Y up, +Z toward viewer. For a front-on
    # planar capture this matches a tangent-space OpenGL map. Perspective
    # surface reconstruction is deliberately not inferred from a single photo.
    maps['normal'] = normalize_normals(maps['normal'])
    return maps, {'model': 'rgbx', 'model_revision': os.environ['RGBX_REVISION'],
                  'inference_steps': 50, 'normal_method': 'front-facing-planar-camera-space'}
